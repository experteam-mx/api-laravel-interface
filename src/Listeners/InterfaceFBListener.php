<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfaceFBListener extends InterfaceBaseListener
{
    public ?Collection $countryPaymentTypeFieldAccounts = null;
    public ?Collection $countryPaymentTypeFieldCartTypes = null;
    public ?Collection $countryReferenceCurrency = null;
    public Collection $deposits;
    public array $closing = [];
    protected int $localCurrencyId = 0;
    public array $countryPaymentTypes = [];
    public string $language = "ES";
    public string $cashAccount = "1261000080";
    public string $checkAccount = "1269000080";
    public string $tolerancePlusCostCenter = "8091850002";
    public string $toleranceMinusCostCenter = "8091850002";
    public string $tolerancePlusAccount = "3626020010";
    public string $toleranceMinusAccount = "3219400030";
    public string $cashAccount_usd = "1261000080";
    public string $checkAccount_usd = "1261000080";
    public array $cashAndCheckPaymentTypes = ['Cash', 'Check'];
    public array $creditDebitCardPaymentTypes = ['Credit Card', 'Debit Card'];
    public array $electronicTransferAndDepositPaymentTypes = ['Transfer', 'Deposit'];

    public function getPayments($event): array
    {
        $this->setLogLine("Start Interface process");

        if (!$this->init($event)) {
            return [
                'success' => true,
                'payments' => Collect([]),
                'message' => 'Interface not generated until configured day of this month'
            ];
        }

        $countryReference = json_decode(Redis::hget('companies.countryReference', $this->countryId), true) ?? null;

        $this->localCurrencyId = $countryReference['currency_id'] ?? 0;

        $this->getCountryReferenceCurrencies();

        $locations = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.locations.get_all'), [
                'company_country_id' => $this->companyCountryId,
                'limit' => 1000
            ]);

        $this->locations = Collect($locations['locations']);

        $locationIds = array_column($locations['locations'], 'id');

        $this->setLogLine("Get location ids " . implode(', ', $locationIds));

        $Closings = ApiClientFacade::setBaseUrl(config('experteam-crud.cash-operations.base_url'))
            ->post(config('experteam-crud.cash-operations.closing.get_all'), [
                'locationIds' => $locationIds,
                'startDateTime' => $this->start,
                'endDateTime' => $this->end
            ]);

        if (!isset($Closings['closings'])) {
            $this->setLogLine(json_encode($Closings));
            return [
                'success' => false,
                'payments' => Collect([]),
                'message' => 'Bad response from Api Cash Operations'
            ];
        }

        if (count($Closings['closings']) == 0) {
            return [
                'success' => true,
                'payments' => Collect([]),
                'message' => 'No closings from Api Cash Operations'
            ];
        }

        $openingIds = $deposits = [];

        foreach ($Closings['closings'] as $closing) {
            $tmpOpeningIds = array_column($closing['openings'], 'id');
            $openingIds = array_merge($openingIds, $tmpOpeningIds);

            $this->closing[] = array_merge($closing, ['openingIds' => $openingIds]);

            if (empty($closing['deposits']))
                continue;

            $tmpDeposits = $closing['deposits'];
            foreach ($tmpDeposits as $key => $deposit) {
                $tmpDeposits[$key]['openingIds'] = $tmpOpeningIds;
            }
            $deposits = array_merge($deposits, $tmpDeposits);
        }

        $this->setLogLine("Get openings ids " . implode(', ', $openingIds));
        $this->setLogLine("Get Deposits " . json_encode($deposits));

        $this->deposits = Collect($deposits);

        $payments = InterfaceFacade::getPaymentsInvoices($openingIds);

        if ($payments->count() == 0) {
            return [
                'success' => true,
                'payments' => Collect([]),
                'message' => 'No payments associated to given openings'
            ];
        }

        $payments = $this->verifyOpenItems($payments);

        $countryPaymentTypesResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.invoices.base_url'))
            ->get(config('experteam-crud.invoices.country_payment_types.get_all'), [
                'country_id' => $this->countryId,
            ]);

        foreach ($countryPaymentTypesResponse['country_payment_types'] as $countryPaymentType) {
            $this->countryPaymentTypes[$countryPaymentType['name']] = $countryPaymentType['id'];
        }

        $this->setLogLine("Get Country Payment Type ids " . json_encode($this->countryPaymentTypes));

        $countryPaymentTypeFieldAccountsResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.invoices.base_url'))
            ->get(config('experteam-crud.invoices.payment_type_field_accounts.get_all'), [
                'country_payment_type_field@country_payment_type_id' => [
                    'in' => implode(',',array_values($this->countryPaymentTypes))
                ],
                'limit' => 500,
            ]);

        $this->countryPaymentTypeFieldAccounts = Collect($countryPaymentTypeFieldAccountsResponse['country_payment_type_field_accounts']);
        $this->setLogLine("Get Country Payment Type Field Accounts");

        $countryPaymentTypeFieldCardTypesResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.invoices.base_url'))
            ->get(config('experteam-crud.invoices.payment_type_field_card_types.get_all'), [
                'country_payment_type_field@country_payment_type_id' => [
                    'in' => implode(',', array_values($this->countryPaymentTypes))
                ],
                'limit' => 500,
            ]);

        $this->countryPaymentTypeFieldCartTypes = Collect($countryPaymentTypeFieldCardTypesResponse['country_payment_type_field_card_types']);
        $this->setLogLine("Get Country Payment Type Field Card Types");

        return [
            'success' => true,
            'payments' => $payments,
            'message' => null
        ];
    }

    public function paymentTypeGrouped($event): array
    {
        try {

            $paymentResponse = $this->getPayments($event);

            if (!is_null($paymentResponse['message'])) {
                $this->setLogLine($paymentResponse['message']);
                return [
                    'success' => $paymentResponse['success'],
                    'message' => $paymentResponse['message'],
                    'detail' => []
                ];
            }

            $payments = $paymentResponse['payments'];

            $response = ['success' => true, 'message' => '', 'detail' => []];
            $cashFile = $this->cashAndCheckFile(
                $payments->whereIn('country_payment_type_id',
                    $this->getPaymentTypeIds($this->cashAndCheckPaymentTypes)
                )
            );

            $this->setLogLine("Get cash and check file");

            $creditDebitCardFile = $this->creditDebitCardFile(
                $payments->whereIn('country_payment_type_id',
                    $this->getPaymentTypeIds($this->creditDebitCardPaymentTypes)
                )
            );

            $this->setLogLine("Get credit and debit card file");

            $electronicTransferAndDepositFile = $this->electronicTransferAndDepositFile(
                $payments->whereIn('country_payment_type_id',
                    $this->getPaymentTypeIds($this->electronicTransferAndDepositPaymentTypes)
                )
            );

            $this->setLogLine("Get electronic transfer and deposit file");

            $actualDateTime = Carbon::now()->format('YmdHis');

            if (!is_null($cashFile)) {
                $this->setLogLine("Sending cash and check file");
                $this->saveAndSentInterface(
                    $cashFile,
                    "FB01_{$this->country}_EYC_$actualDateTime.txt",
                    'Cash and Check'
                );
            } else {
                $this->setLogLine("No payments in Cash or Check");
            }

            if (!is_null($creditDebitCardFile)) {
                $this->setLogLine("Sending Credit and Debit Card file");
                $this->saveAndSentInterface(
                    $creditDebitCardFile,
                    "FB01_{$this->country}_TRJ_{$actualDateTime}.txt",
                    'Credit and Debit Card'
                );
            } else {
                $this->setLogLine("No payments in Credit or Debit Card");
            }

            if (!is_null($electronicTransferAndDepositFile)) {
                $this->setLogLine("Sending Electronic Transfer and Deposit file");
                $this->saveAndSentInterface(
                    $electronicTransferAndDepositFile,
                    "FB01_{$this->country}_TYD_{$actualDateTime}.txt",
                    'Electronic Transfer and Deposit'
                );
            } else {
                $this->setLogLine("No payments in Electronic Transfer or Deposit");
            }

        } catch (\Exception $e) {
            $this->setLogLine("Error generating files: " . $e->getMessage());
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function singleFile($event): array
    {
        $response = ['success' => true, 'message' => '', 'detail' => []];
        try {
            $paymentResponse = $this->getPayments($event);

            if (!is_null($paymentResponse['message'])) {
                $this->setLogLine($paymentResponse['message']);
                return [
                    'success' => $paymentResponse['success'],
                    'message' => $paymentResponse['message'],
                    'detail' => []
                ];
            }

            $payments = $paymentResponse['payments'];

            $fileContent = $this->getSingleFileContent($payments);

            $actualDateTime = Carbon::now()->format('YmdHis');

            $this->setLogLine("Sending General file");
            $this->saveAndSentInterface(
                $fileContent,
                "FB01_{$this->country}_$actualDateTime.txt",
                'General'
            );
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function getSingleFileContent($payments): string
    {
        $paymentsArr = $payments->toArray();

        foreach ($paymentsArr as &$p) {
            $p['document_id'] = $p['documents'][0]['id'];
        }

        $payments = array_values(Collect($paymentsArr)->sortBy('document_id')->toArray());

        $fileContent = '';
        $countHeaders = $countLines = $hInlineCount = 0;

        $this->setLogLine("Get general file");

        foreach ($payments as $index => $payment) {
            if (empty($location))
                $location = $this->getLocation($payment['installation_id']);

            if (!isset($payments[$index - 1]) ||
                $payments[$index - 1]['document_id'] != $payment['document_id']) {
                $location = $this->getLocation($payment['installation_id']);
                $fileContent .= $this->headerLine($payment, 'COB COUNTER BR ', $location);
                $countHeaders++;
            }

            switch (true) {
                case (in_array($payment['country_payment_type_id'], $this->getPaymentTypeIds($this->electronicTransferAndDepositPaymentTypes))):
                    [$returnFileContent, $returnLine] = $this->electronicTransferAndDepositLine($payment, $location, $hInlineCount);
                    $fileContent .= $returnFileContent;
                    $hInlineCount = $returnLine;
                    break;
                case (in_array($payment['country_payment_type_id'], $this->getPaymentTypeIds($this->cashAndCheckPaymentTypes))):
                    [$returnFileContent, $returnLine] = $this->cashAndCheckLine($payment, $location, $hInlineCount);
                    $fileContent .= $returnFileContent;
                    $hInlineCount = $returnLine;
                    break;
                case (in_array($payment['country_payment_type_id'], $this->getPaymentTypeIds($this->creditDebitCardPaymentTypes))):
                    [$returnFileContent, $returnLine] = $this->creditDebitCardLine($payment, $location, $hInlineCount);
                    $fileContent .= $returnFileContent;
                    $hInlineCount = $returnLine;
                    break;
                default:
                    dd('country_payment_type_id' .
                        $payment['country_payment_type_id'] . ' not defined on interfaces');
            }

            if (!isset($payments[$index + 1]) ||
                $payments[$index + 1]['document_id'] != $payment['document_id']) {
                $hInlineCount++;
                $fileContent .= $this->formatCUSLine($payment, $hInlineCount, ' ', $location, true);
                $countLines += $hInlineCount;
                $hInlineCount = 0;
            }
        }

        return $this->setFileLastLine($fileContent, $countHeaders, $countLines);
    }

    public function getPaymentTypeIds(array $array): array
    {
        $return = [];
        foreach ($this->countryPaymentTypes as $key => $id) {
            if (in_array($key, $array))
                $return[] = $id;
        }
        return $return;
    }

    public function headerLine($payment, string $headerString, array $location): string
    {
        $headerString = str_pad($headerString, 15);

        $invoiceNumber = $this->getInvoiceNumber($payment);

        $countryReferenceCurrency = $this->countryReferenceCurrency
            ->where('id', $payment['country_reference_currency_id'])
            ->first();

        return "H  $this->countryCode     DZ" . $this->getClosingDatetime($payment)->format('d.m.Y') . $this->getDatetimeGmt(Carbon::now())->format('d.m.Y') .
            "{$headerString}{$location['location_code']} " . $this->getClosingDatetime($payment)->format('d-m') . " " . //character 35 to 59
            "{$countryReferenceCurrency['currency']['code']}  " . $this->formatStringLength(number_format($payment['exchange'], 4, '.', ''), 9, true) .
            $this->formatStringLength($invoiceNumber, 16) . " " . PHP_EOL; //character 60 to 109
    }

    public function formatDZGlLine($payment, $paymentCode, $account, $hInlineCount, $allocationNumber, $itemText): string
    {
        return "GL " . sprintf("%010d", $hInlineCount) . $paymentCode . $account . " " . //characters 1 to 26
            str_pad(
                number_format($payment['received'], 2, '.', ''),
                23, ' ', STR_PAD_LEFT) . //characters 27 to 49
            str_pad(" ", 75) . //characters 50 to 124
            $allocationNumber . $itemText . " " . PHP_EOL;
    }

    public function formatDZGLToleranceLine($tolerance, $hInlineCount, $allocationNumber, $itemText): string
    {
        $code = $tolerance['amount'] < 0 ? "40" : "50";
        $account = $tolerance['amount'] < 0 ? $this->tolerancePlusAccount : $this->toleranceMinusAccount;
        $toleranceCenter = $tolerance['amount'] < 0 ? $this->tolerancePlusCostCenter : $this->toleranceMinusCostCenter;
        return "GL " . sprintf("%010d", $hInlineCount) . $code . $account . " " . //characters 1 to 26
            str_pad(number_format(abs($tolerance['amount']), 2, '.', ''), 23, ' ', STR_PAD_LEFT) . //characters 27 to 49
            str_pad(" ", 55) . //characters 50 to 104
            $this->formatStringLength($toleranceCenter, 20) . //characters 105 to 125
            $allocationNumber . $itemText . " " . PHP_EOL;
    }

    public function formatCUSLine($payment, $hInlineCount, $allocationNumber, $location, bool $severalLines = false): string
    {

        $allocationNumber = $this->formatStringLength($allocationNumber, 18);

        $accountNumber = $this->getAccountNumber($payment);

        $accountNumber = Str::padLeft($accountNumber, 10, '0');
        $paymentDueDate = Carbon::create($payment['due_date']);
        $total = $payment['amount'];

        $content = '';

        if (empty($payment['fixed_details'])) {
            foreach ($payment['documents'] as $document) {
                foreach ($this->getHeaderItems($document) as $item) {
                    if ($severalLines) {
                        $total = $this->getTotalAmountItems($document, $item);
                    }

                    $paymentDueDate = Carbon::create($payment['due_date']);
                    $customerPostalCode = $document['document']['customer_postal_code'] ?? 'N/A';

                    $content .= "CUS" . sprintf("%010d", $hInlineCount) . "15" . $accountNumber . " " . //characters 1 to 26
                        str_pad(
                            number_format($total, 2, '.', ''),
                            23, ' ', STR_PAD_LEFT) . //characters 27 to 49
                        str_pad(" ", 43) . //characters 50 to 92
                        $paymentDueDate->format('d.m.Y') . str_pad(" ", 22) . //characters 93 to 124
                        $allocationNumber . str_pad(" ", 52) . //characters 125 to 195
                        $this->formatStringLength($document['document']['customer_company_name'], 35) . //characters 195 to 230
                        $this->formatStringLength($document['document']['customer_address_line1'], 35) . //characters 231 to 265
                        $location['facility_code'] . //characters 266 to 268
                        str_pad(" ", 32) . "$this->country " . $this->language . $this->formatStringLength($customerPostalCode, 10) .
                        $this->formatStringLength($document['document']['customer_identification_number'] ?? '', 16) .
                        "    " . "01 " . PHP_EOL;//todo: map region code

                    if (!$severalLines) {
                        break;
                    }
                }
            }
        } else {
            if ($severalLines) {
                $total ??= $payment['fixed_details']['payed_value'];
            }
            $allocationNumber = ($payment['fixed_details']['kind'] == 'openItem') ? $allocationNumber : $this->formatStringLength('ABONO', 18);
            $facilityCode = ($payment['fixed_details']['kind'] == 'openItem') ? $location['facility_code'] : '.  ';
            $content .= "CUS" . sprintf("%010d", $hInlineCount) . "15" . $accountNumber . " " . //characters 1 to 26
                str_pad(
                    number_format($total, 2, '.', ''),
                    23, ' ', STR_PAD_LEFT) . //characters 27 to 49
                str_pad(" ", 43) . //characters 50 to 92
                $paymentDueDate->format('d.m.Y') . str_pad(" ", 22) . //characters 93 to 124
                $allocationNumber . str_pad(" ", 52) . //characters 125 to 195
                $this->formatStringLength($payment['fixed_details']['customer_company_name'], 35) . //characters 195 to 230
                $this->formatStringLength('.', 35) . //characters 231 to 265
                $facilityCode . //characters 266 to 268
                str_pad(" ", 32) . "$this->country " . $this->language . $this->formatStringLength('N/A', 10) .
                $this->formatStringLength($payment['fixed_details']['customer_identification_number'] ?? '', 16) .
                "    " . "01 " . PHP_EOL;//todo: map region code
        }

        return $content;
    }

    public function cashAndCheckFile(Collection $payments): ?string
    {
        if ($payments->count() == 0)
            return null;

        $fileContent = '';
        $countHeaders = $countLines = 0;
        foreach ($payments as $payment) {
            $location = $this->getLocation($payment['installation_id']);
            $fileContent .= $this->headerLine($payment, 'COBRANZA COUNT ', $location);
            $countHeaders++;

            [$returnFileContent, $returnLine] = $this->cashAndCheckLine($payment, $location, 0);
            $fileContent .= $returnFileContent;

            $allocationNumber = $this->getTrackingNumber($payment);
            $hInlineCount = $returnLine + 1;
            $fileContent .= $this->formatCUSLine($payment, $hInlineCount, $allocationNumber, $location);
            $countLines += $hInlineCount;
        }

        return $this->setFileLastLine($fileContent, $countHeaders, $countLines);
    }

    public function creditDebitCardFile(Collection $payments): ?string
    {
        if ($payments->count() == 0)
            return null;

        $fileContent = '';
        $countHeaders = $countLines = 0;
        foreach ($payments as $payment) {
            $location = $this->getLocation($payment['installation_id']);
            $fileContent .= $this->headerLine($payment, 'COBRANZA COUNT ', $location);
            $countHeaders++;

            [$returnFileContent, $returnLine] = $this->creditDebitCardLine($payment, $location, 0);
            $fileContent .= $returnFileContent;

            $allocationNumber = $this->getTrackingNumber($payment);

            $hInlineCount = $returnLine + 1;
            $fileContent .= $this->formatCUSLine($payment, $hInlineCount, $allocationNumber, $location);
            $countLines += $hInlineCount;
        }

        return $this->setFileLastLine($fileContent, $countHeaders, $countLines);
    }

    public function electronicTransferAndDepositFile(Collection $payments): ?string
    {
        if ($payments->count() == 0)
            return null;

        $fileContent = '';
        $countHeaders = $countLines = 0;
        foreach ($payments as $payment) {
            $location = $this->getLocation($payment['installation_id']);
            $fileContent .= $this->headerLine($payment, 'COBRANZA COUNT ', $location);
            $countHeaders++;

            [$returnFileContent, $returnLine] = $this->electronicTransferAndDepositLine($payment, $location, 0);
            $fileContent .= $returnFileContent;

            $allocationNumber = $this->getTrackingNumber($payment);

            $hInlineCount = $returnLine + 1;
            $fileContent .= $this->formatCUSLine($payment, $hInlineCount, $allocationNumber, $location);
            $countLines += $hInlineCount;
        }

        return $this->setFileLastLine($fileContent, $countHeaders, $countLines);
    }

    public function cashAndCheckLine(array $payment, $location, int $line): array
    {
        $depositNumber = $this->getDepositNumber($payment);

        $allocationNumber = $this->formatStringLength($depositNumber, 18);
        //E Efectivo , C Cheche

        if ($payment['country_payment_type_id'] == $this->countryPaymentTypes['Cash']) {
            $account = $this->isLocalCurrencyPayment($payment) ? $this->cashAccount : $this->cashAccount_usd;
            $code = 'E';
            $paymentNumber = "000000";
        } else {
            $account = $this->isLocalCurrencyPayment($payment) ? $this->checkAccount : $this->checkAccount_usd;
            $code = 'C';

            $fields = Collect($payment['details']);
            $numberField = $fields->where('code', 'number')->first();
            $number = $numberField['value'];

            $paymentNumber = Str::padLeft(Str::reverse(Str::limit(Str::reverse($number), 6, '')), 6, '0');
        }

        $user = $this->getUser($payment['documents'][0]['user_id']);
        $username = $user['username'];
        $itemText = $this->formatStringLength($location['location_code'] . "000" . $this->getClosingDatetime($payment)->format('md') . "$code/$paymentNumber/$username", 50);

        $line++;
        $fileContent = $this->formatDZGlLine($payment, "40", $account, $line, $allocationNumber, $itemText);

        $paymentAdjustments = Collect($payment['payment_adjustment'] ?? []);
        if ($paymentAdjustments->count() > 0 &&
            $paymentAdjustments->where('adjustment_type', 0)->count() > 0) {
            $tolerance = $paymentAdjustments->where('adjustment_type', 0)->first();
            if (round(abs($tolerance['amount']), 2) != 0.00) {
                $line++;
                $allocationNumber = $this->formatStringLength($username, 18);
                $itemText = $this->formatStringLength(" ", 50);
                $fileContent .= $this->formatDZGLToleranceLine($tolerance, $line, $allocationNumber, $itemText);
            }
        }
        return [$fileContent, $line];
    }

    public function creditDebitCardLine(array $payment, $location, int $line): array
    {
        $fields = Collect($payment['details']);

        $lot = $fields->where('code', 'lot_number')->first();
        $authorization = $fields->where('code', 'authorization')->first();
        $typeCardField = $fields->where('code', 'type_card')->first();

        $typeCard = $this->countryPaymentTypeFieldCartTypes->where('id', $typeCardField['value'])
            ->first();

        $authorizationNumber = Str::padLeft(Str::reverse(Str::limit(Str::reverse($authorization['value'] ?? '000000'), 6, '')), 6, '0');
        $loteNumber = Str::reverse(Str::limit(Str::reverse($lot['value'] ?? $authorization['value'] ?? '-'), 18, ''));
        $cardIssuerCode = $typeCard['code'];

        $account = ($this->isLocalCurrencyPayment($payment) || empty($typeCard['accountable_account_usd'])) ?
            $typeCard['accountable_account'] :
            $typeCard['accountable_account_usd'];

        $user = $this->getUser($payment['documents'][0]['user_id']);
        $username = $user['username'];

        $allocationNumber = $this->formatStringLength($loteNumber, 18);
        $itemText = $this->formatStringLength("{$location['iata_code']}{$location['location_code']}{$this->getClosingDatetime($payment)->format('md')}$cardIssuerCode/$authorizationNumber/$username", 50);

        $line++;
        $content = $this->formatDZGlLine($payment, "40", $account, $line, $allocationNumber, $itemText);
        return [$content, $line];

    }

    public function electronicTransferAndDepositLine(array $payment, $location, int $line): array
    {
        switch ($payment['country_payment_type_id']) {
            case $this->countryPaymentTypes['Transfer']:
                $transactionType = 'T';
                $numberField = 'reference';
                break;
            default:
                $transactionType = 'D';
                $numberField = 'reference';
        }

        $fields = Collect($payment['details']);

        $numberRegister = $fields->where('code', $numberField)->first();
        $accountField = $fields->where('code', 'account')->first();

        $number = $numberRegister['value'];

        $allocationNumber = $this->formatStringLength(Str::reverse(Str::limit(Str::reverse($number), 18,'')), 18);
        $user = $this->getUser($payment['documents'][0]['user_id']);
        $username = $user['username'];

        $numberToSix = Str::padLeft(Str::reverse(Str::limit(Str::reverse($number), 6,'')), 6, '0');
        $itemText = $this->formatStringLength("{$location['location_code']}000{$this->getClosingDatetime($payment)->format('md')}$transactionType/$numberToSix/$username", 50);

        $accountRegister = $this->countryPaymentTypeFieldAccounts->where('id', $accountField['value'])->first();

        $line++;
        $content = $this->formatDZGlLine($payment, "40", $accountRegister['accountable_account'], $line, $allocationNumber, $itemText);

        return [$content, $line];
    }

    public function setFileLastLine(string $fileContent, int $countHeaders, int $countLines): string
    {
        $countHeaders = sprintf("%010d", $countHeaders);
        $countLines = sprintf("%010d", $countLines);
        return $fileContent . "TOT{$countHeaders}{$countLines}" . PHP_EOL;
    }

    public function getDepositNumber($payment): string
    {
        $depositNumber = null;
        foreach ($this->deposits
                     ->where('countryReferenceCurrencyId', $payment['country_reference_currency_id'])
                     ->all() as $deposit) {
            if (in_array($payment['opening_id'], $deposit['openingIds']) &&
                Collect($deposit['depositDetails'])
                    ->where('countryPaymentTypeId', $payment['country_payment_type_id'])->count() != 0) {
                $depositNumber = $deposit['number'];
            }
        }

        if (is_null($depositNumber)) {
            $depositNumber = $this->getClosing($payment)['id'] ?? null;
        }

        return $depositNumber;
    }

    protected function getClosing($payment): array|null
    {
        foreach ($this->closing as $closing) {
            if (in_array($payment['opening_id'], $closing['openingIds'])) {
                return $closing;
            }
        }
        return null;
    }

    protected function getClosingDatetime($payment): Carbon
    {
        return $this->getDatetimeGmt(Carbon::parse($this->getClosing($payment)['createdAt']) ?? Carbon::now());
    }

    public function getCountryReferenceCurrencies(): void
    {
        $countryReferenceCurrencies = Redis::hgetall('companies.countryReferenceCurrency');
        $countryReferenceCurrencyList = [];
        foreach ($countryReferenceCurrencies as $countryReferenceCurrency) {
            $countryReferenceCurrency = json_decode($countryReferenceCurrency, true);
            if ($countryReferenceCurrency['country_id'] == $this->countryId) {
                $countryReferenceCurrency['currency'] = json_decode(Redis::hget('catalogs.currency', $countryReferenceCurrency['currency_id']), true);
                $countryReferenceCurrencyList[] = $countryReferenceCurrency;
            }
        }
        $this->countryReferenceCurrency = Collect($countryReferenceCurrencyList);
    }

    private function getTotalAmountItems($document, $item): float
    {

        if (!empty($item['details']['header']['total'])) return (float)$item['details']['header']['total'];

        $total = $item['total'];

        foreach ($document['items'] as $i) {
            if ($item['details']['header']['awbNumber'] == ($i['details']['relation'] ?? '')) {
                $total += (float)$i['total'];
            }
        }

        return $total;
    }

    protected function getInvoiceNumber($payment): string
    {
        if (!empty($payment['fixed_details']['invoice_number']))
            return $payment['fixed_details']['invoice_number'];

        $document = $payment['documents'][0]['document'];
        return $document['document_prefix'] . $document['document_number'];
    }

    protected function getCustomerIdentificationNumber($payment)
    {
        return $payment['fixed_details']['customer_identification_number'] ??
            $payment['documents'][0]['document']['customer_identification_number'];
    }

    protected function getTrackingNumber($payment): string
    {
        return $payment['fixed_details']['shipment_tracking_number'] ?? $this->getHeaderItems($payment['documents'][0])[0]['details']['header']['awbNumber'];
    }

    protected function getAccountNumber($payment)
    {
        return $payment['fixed_details']['account'] ?? $this->getHeaderItems($payment['documents'][0])[0]['details']['header']['accountNumber'];
    }

    protected function isLocalCurrencyPayment($payment): bool
    {
        $countryReferenceCurrency = $this->countryReferenceCurrency
            ->where('id', $payment['country_reference_currency_id'])
            ->first();

        return $countryReferenceCurrency['currency_id'] == $this->localCurrencyId;
    }

    protected function verifyOpenItems($payments)
    {

        $newPayments = Collect([]);
        $openItemsPayment = [];
        foreach ($payments->toArray() as $payment) {
            $item = $payment['documents'][0]['items'][0];

            if ($item['model_type'] == 'OpenItem') {
                /** Filter same document payments to divide all payments across al documents */
                if (!empty($openItemsPayment[$payment['id']])) {
                    unset($openItemsPayment[$payment['id']]);
                    continue;
                }
                $tmPayments = $payments->filter(function (array $p) use ($payment) {
                    return $p['documents'][0]['id'] == $payment['documents'][0]['id'];
                });

                $openItemsPayment = array_flip([...array_flip($openItemsPayment), ...array_map(fn ($p) => $p['id'], $tmPayments->toArray())]);

                unset($openItemsPayment[$payment['id']]);

                foreach ($this->formatOpenItemsPayments($tmPayments) as $p) {
                    $newPayments->push($p);
                }
            } elseif($item['model_type'] == 'AccountPayment') {
                $payment['fixed_details'] = [
                    'invoice_number' => $payment['documents'][0]['document_prefix'] . $payment['documents'][0]['document_number'],
                    'shipment_tracking_number' => '.',
                    'customer_identification_number' => '.',
                    'customer_company_name' => '.',
                    'account' => $payment['documents'][0]['items'][0]['details']['account'],
                    'kind' => 'accountCollection',
                ];
                $newPayments->push($payment);
            } else {
                $newPayments->push($payment);
            }
        }
        return $newPayments;
    }

    protected function formatOpenItemsPayments($tmPayments): array
    {
        $paymentsReturn = [];
        $openItems = array_map(fn($item) => $item['details'], array_values($tmPayments->toArray())[0]['documents'][0]['items']);

        $openItemsTotal = array_sum(array_column($openItems, 'payed_value'));

        $openItemsLength = count($openItems);

        $paymentValues = [];

        foreach ($openItems as $k => $openItem) {
            $factor = $openItem['payed_value'] / $openItemsTotal;

            foreach ($tmPayments as $payment) {
                if ($factor == 1) { // one open item, one or several payments
                    $amount = $payment['received'];
                } else { // several open items
                    if (($k + 1) == $openItemsLength) { // last open item
                        $amount = $payment['received'] - $paymentValues[$payment['id']];
                    } else {
                        $amount = round($payment['received'] * $factor, 2);
                        $paymentValues[$payment['id']] = ($paymentValues[$payment['id']] ?? 0) + $amount;
                    }
                }
                $payment['received'] = $amount;
                $payment['amount'] = $amount;
                $payment['fixed_details'] = [
                    'invoice_number' => $openItem['invoice_number'],
                    'shipment_tracking_number' => $openItem['shipment_tracking_number'],
                    'customer_identification_number' => $openItem['customer_identification_number'],
                    'customer_company_name' => $openItem['customer_company_name'],
                    'account' => $openItem['account'],
                    'payed_value' => $openItem['payed_value'],
                    'kind' => 'openItem',
                ];
                $paymentsReturn[] = $payment;
            }
        }
        return $paymentsReturn;
    }
}
