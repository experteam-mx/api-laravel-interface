<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Experteam\ApiLaravelInterface\Models\InterfaceFile;
use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfacePaymentsFBListener extends InterfacePaymentsBaseListener
{
    private ?Collection $countryPaymentTypeFieldAccounts = null;
    private ?Collection $countryPaymentTypeFieldCartTypes = null;
    private ?Collection $companyCountryCurrency = null;
    private Collection $deposits;
    private array $closing = [];
    private array $countryPaymentTypes = [];
    private string $language = "ES";
    private string $countryCode = "EC10";
    private string $cashAccount = "1261000080";
    private string $checkAccount = "1269000080";
    private string $tolerancePlusCostCenter = "8091850002";
    private string $toleranceMinusCostCenter = "8091850002";
    private string $tolerancePlusAccount = "3626020010";
    private string $toleranceMinusAccount = "3219400030";
    private string $cashAccount_usd = "1261000080";
    private string $checkAccount_usd = "1261000080";
    private string $tolerancePlusCostCenter_usd = "8091850002";
    private string $toleranceMinusCostCenter_usd = "8091850002";
    private string $tolerancePlusAccount_usd = "3626020010";
    private string $toleranceMinusAccount_usd = "3219400030";
    private string $creditCardAccount_usd = "1263004014";
    private string $debitCardAccount_usd = "1263004014";

    public function handle($event): void
    {
        $this->setLogLine("Start Interface process");
        $this->interfaceRequestId = $event->interfaceRequest->id;
        $this->toSftp = $event->interfaceRequest->to_sftp;

        $start = is_null($event->interfaceRequest->to) ?
            Carbon::yesterday()->startOfDay() :
            Carbon::create($event->interfaceRequest->to);
        $end = is_null($event->interfaceRequest->from) ?
            Carbon::yesterday()->endOfDay() :
            Carbon::create($event->interfaceRequest->from);

        $this->setLogLine("Interface required from " . $start->format('Y-m-d') . " to " . $end->format('Y-m-d'));
        $start = $start->addHours(4)
            ->format('Y-m-d H:i:s');

        $end = $end->addHours(4)
            ->format('Y-m-d H:i:s');

        $openingIds = $deposits = [];

        try {

            $countryId = intval(json_decode(Redis::hget('catalogs.country.code', $this->country))->id);

            $this->setLogLine("Get $this->country country id " . $countryId);

            $companyCountries = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
                ->get(config('experteam-crud.companies.company-countries.get_all'), [
                'country_id' => $countryId,
                'company@name' => $event->company ?? 'DHL'
            ]);

            $companyCountryId = $companyCountries['company_countries'][0]['id'];

            $this->setLogLine("Get company country id " . $companyCountryId);

            $this->companyCountryCurrency = $this->getCompanyCountryCurrencies($companyCountryId);

            $locations = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
                ->get(config('experteam-crud.companies.locations.get_all'),[
                'company_country_id' => $companyCountryId,
                'limit' => 1000
            ]);

            $this->locations = Collect($locations['locations']);

            $locationIds = array_column($locations['locations'], 'id');

            $this->setLogLine("Get location ids " . implode(', ', $locationIds));

            $Closings = ApiClientFacade::setBaseUrl(config('experteam-crud.cash-operations.base_url'))
                ->get(config('experteam-crud.cash-operations.closing.get_all'),[
                'locationIds' => $locationIds,
                'startDateTime' => $start,
                'endDateTime' => $end
            ]);

            if (!isset($Closings['closings'])) {
                $this->setLogLine("Bad response from Api Cash Operations");
                $this->setLogLine(json_encode($Closings));
                return;
            }

            if (count($Closings['closings']) == 0) {
                $this->setLogLine("No closings from Api Cash Operations");
                return;
            }

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
                $this->setLogLine("No payments associated to given openings");
                return;
            }

            $countryPaymentTypesResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.invoices.base_url'))
                ->get(config('experteam-crud.invoices.country_payment_types.get_all'),[
                'country_id' => $countryId,
            ]);

            foreach ($countryPaymentTypesResponse['country_payment_types'] as $countryPaymentType) {
                $this->countryPaymentTypes[$countryPaymentType['name']] = $countryPaymentType['id'];
            }

            $this->setLogLine("Get Country Payment Type ids " . json_encode($this->countryPaymentTypes));

            $countryPaymentTypeFieldAccountsResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.invoices.base_url'))
                ->get(config('experteam-crud.invoices.payment_type_field_accounts.get_all'),[
                'country_payment_type_field@country_payment_type_id' => ['in' => [
                    $this->countryPaymentTypes['Transfer'],
                ]],
            ]);

            $this->countryPaymentTypeFieldAccounts = Collect($countryPaymentTypeFieldAccountsResponse['country_payment_type_field_accounts']);
            $this->setLogLine("Get Country Payment Type Field Accounts");

            $countryPaymentTypeFieldCardTypesResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.invoices.base_url'))
                ->get(config('experteam-crud.invoices.payment_type_field_card_types.get_all'),[
                'country_payment_type_field@country_payment_type_id' => ['in' => [
                    $this->countryPaymentTypes['Credit Card'],
                    $this->countryPaymentTypes['Debit Card'],
                ]],
            ]);

            $this->countryPaymentTypeFieldCartTypes = Collect($countryPaymentTypeFieldCardTypesResponse['country_payment_type_field_card_types']);
            $this->setLogLine("Get Country Payment Type Field Card Types");

            $this->paymentTypeGrouped($payments);
//            $this->singleFile($payments);

            $event->interfaceRequest->update(['status' => 1]);

        } catch (\Exception $e) {
            dump($e);
            $event->interfaceRequest->update(['status' => 2]);
        }
    }

    public function paymentTypeGrouped(Collection $payments): void
    {

        $cashFile = $this->cashAndCheckFile(
            $payments->where('country_payment_type_id', $this->countryPaymentTypes['Cash'])
        );

        $this->setLogLine("Get cash and check file");

        $creditDebitCardFile = $this->creditDebitCardFile(
            $payments->whereIn('country_payment_type_id', [
                $this->countryPaymentTypes['Credit Card'],
                $this->countryPaymentTypes['Debit Card']
            ])
        );

        $this->setLogLine("Get credit and debit card file");

        $electronicTransferAndDepositFile = $this->electronicTransferAndDepositFile(
            $payments->whereIn('country_payment_type_id', [
                $this->countryPaymentTypes['Transfer']
            ])
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

    }

    public function singleFile(Collection $payments): void
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

            switch ($payment['country_payment_type_id']) {
                case $this->countryPaymentTypes['Transfer']:
                    [$returnFileContent, $returnLine] = $this->electronicTransferAndDepositLine($payment, $location, $hInlineCount);
                    $fileContent .= $returnFileContent;
                    $hInlineCount = $returnLine;
                    break;
                case $this->countryPaymentTypes['Cash']:
                case $this->countryPaymentTypes['Check']:
                    [$returnFileContent, $returnLine] = $this->cashAndCheckLine($payment, $location, $hInlineCount);
                    $fileContent .= $returnFileContent;
                    $hInlineCount = $returnLine;
                    break;
                case $this->countryPaymentTypes['Credit Card']:
                case $this->countryPaymentTypes['Debit Card']:
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

        $fileContent = $this->setFileLastLine($fileContent, $countHeaders, $countLines);

        $actualDateTime = Carbon::now()->format('YmdHis');

        $this->setLogLine("Sending General file");
        $this->saveAndSentInterface(
            $fileContent,
            "FB01_{$this->country}_$actualDateTime.txt",
            'General'
        );
    }

    public function headerLine($payment, string $headerString, array $location): string
    {
        $headerString = str_pad($headerString, 15);

        $invoiceNumber = $this->getInvoiceNumber($payment);

        $companyCountryCurrency = $this->companyCountryCurrency
            ->where('id', $payment['company_country_currency_id'])
            ->first();

        return "H  " . $this->countryCode . "     DZ" . $this->getClosingDatetime($payment)->format('d.m.Y') . Carbon::now()->format('d.m.Y') .
            "{$headerString}{$location['location_code']} " . $this->getClosingDatetime($payment)->format('d-m') . " " . //character 35 to 59
            "{$companyCountryCurrency['currency']['code']}  " . $this->formatStringLength(number_format($payment['exchange'], 4, '.', ''), 9, true) .
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
        $accountNumber = $this->getHeaderItems($payment['documents'][0])[0]['details']['header']['accountNumber'];

        $accountNumber = Str::padLeft($accountNumber, 10 , '0');

        $content = '';

        foreach ($payment['documents'] as $document) {
            foreach ($this->getHeaderItems($document) as $item) {
                if ($severalLines) {
                    $total = $this->getTotalAmountItems($document, $item);
                } else {
                    $total = $payment['amount'];
                }

                $paymentDueDate = Carbon::create($payment['due_date']);
                $customerPostalCode = $document['customer_postal_code'] ?? 'N/A';

                $content .= "CUS" . sprintf("%010d", $hInlineCount) . "15" . $accountNumber . " " . //characters 1 to 26
                    str_pad(
                        number_format($total, 2, '.', ''),
                        23, ' ', STR_PAD_LEFT) . //characters 27 to 49
                    str_pad(" ", 43) . //characters 50 to 92
                    $paymentDueDate->format('d.m.Y') . str_pad(" ", 22) . //characters 93 to 124
                    $allocationNumber . str_pad(" ", 52) . //characters 125 to 195
                    $this->formatStringLength($document['customer_company_name'], 35) . //characters 195 to 230
                    $this->formatStringLength($document['customer_address_line1'], 35) . //characters 231 to 265
                    $location['facility_code'] . //characters 266 to 268
                    str_pad(" ", 32) . "$this->country " . $this->language . $this->formatStringLength($customerPostalCode, 10) .
                    $this->formatStringLength($document['customer_identification_number'] ?? '', 16) .
                    "    " . "01 " . PHP_EOL;//todo: map region code

                if (!$severalLines) {
                    break;
                }
            }
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

        $isLocalCurrency = number_format($payment['exchange'], 4) == number_format(1, 4);
        if ($payment['country_payment_type_id'] == $this->countryPaymentTypes['Cash']) {
            $account = $isLocalCurrency ? $this->cashAccount : $this->cashAccount_usd;
            $code = 'E';
            $paymentNumber = "000000";
        } else {
            $account = $isLocalCurrency ? $this->checkAccount : $this->checkAccount_usd;
            $code = 'C';

            $fields = Collect($payment['details']);
            $numberField = $fields->where('code', 'number')->first();
            $number = $numberField['value'];

            $paymentNumber = Str::padLeft(Str::limit($number,6,''), 6,'0');
        }

        $user = $this->getUser($payment['documents'][0]['user_id']);
        $username = $user['username'];
        $itemText = $this->formatStringLength($location['location_code'] . "000" . $this->getClosingDatetime($payment)->format('md') . "$code/$paymentNumber/$username", 50);

        $line++;
        $fileContent = $this->formatDZGlLine($payment, "40", $account, $line, $allocationNumber, $itemText);

        $paymentAdjustments = Collect($payment['payment_adjustment'] ?? []);
        if ($paymentAdjustments->count() > 0 &&
            $paymentAdjustments->where('adjustment_type', 0)->count() > 0) {
            $line++;
            $tolerance = $paymentAdjustments->where('adjustment_type', 0)->first();
            $allocationNumber = $this->formatStringLength($username, 18);
            $itemText = $this->formatStringLength(" ", 50);
            $fileContent .= $this->formatDZGLToleranceLine($tolerance, $line, $allocationNumber, $itemText);
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

        $authorizationNumber = Str::padLeft(Str::limit($authorization['value'],6,''), 6,'0');
        $loteNumber = $lot['value'] ?? '-';
        $cardIssuerCode = $typeCard['code'];
        $account = $typeCard['accountable_account'];

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
                $transactionType = '';
                $numberField = '';
        }

        $fields = Collect($payment['details']);

        $numberRegister = $fields->where('code', $numberField)->first();
        $accountField = $fields->where('code', 'account')->first();

        $number = $numberRegister['value'];

        $allocationNumber = $this->formatStringLength($number, 18);
        $user = $this->getUser($payment['documents'][0]['user_id']);
        $username = $user['username'];

        $numberToSix = Str::padLeft(Str::limit($number,6,''), 6,'0');
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

    public function formatStringLength(string $string, int $length, bool $left = false): string
    {
        return str_pad(substr($string, 0, $length), $length, ' ', $left ? STR_PAD_LEFT : STR_PAD_RIGHT);
    }

    public function getLocation($installationId)
    {
        $locationId = intval(json_decode(
            Redis::hget('companies.installation', $installationId)
        )?->location_id ?? 0);
        return $this->locations->where('id', $locationId)->first();

    }

    public function getUser($userId)
    {
        return json_decode(Redis::hget('security.user', $userId), true);
    }

    public function saveAndSentInterface(string $fileContent, string $fileName, string $type): void
    {
        \Storage::put("$type/$fileName", $fileContent);

        $interfaceFilesystem = $this->getInterfaceFilesystem();

        if ($this->toSftp) {
            [$transmissionOutput, $success] = InterfaceFacade::sendToInterface($fileName, $fileContent, $this->destFolder, $interfaceFilesystem);
        } else {
            $transmissionOutput = 'Do not sent interface';
            $success = 1;
        }

        InterfaceFile::create([
            'name' => $fileName,
            'interface_request_id' => $this->interfaceRequestId,
            'type' => $type,
            'transmission_output' => $transmissionOutput,
            'status' => $success ? 1 : 2,
        ]);

    }

    public function getHeaderItems($document): array
    {
        $items = Collect($document['items']);
        return array_values($items->where('model_origin', 'shipments')
            ->where('model_type', 'Shipment')
            ->all());
    }

    public function getDepositNumber($payment): string
    {
        $depositNumber = null;
        foreach ($this->deposits
                     ->where('countryPaymentTypeId', $payment['country_payment_type_id'])
                     ->where('companyCountryCurrencyId', $payment['company_country_currency_id'])
                     ->all() as $deposit) {
            if (in_array($payment['opening_id'], $deposit['openingIds'])) {
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
        return Carbon::parse($this->getClosing($payment)['createdAt']) ?? Carbon::now();
    }

    private function getTotalAmountItems($document, $item): float
    {
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
        $document = $payment['documents'][0]['document'];
        return $document['document_prefix'] . $document['document_number'];
    }

    protected function getTrackingNumber($payment): string
    {
        return $this->getHeaderItems($payment['documents'][0])[0]['details']['header']['awbNumber'];
    }
}
