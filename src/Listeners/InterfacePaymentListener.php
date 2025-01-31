<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;
use App\Models\LocationCostCenter;

class InterfacePaymentListener extends InterfaceBaseListener
{
    public function processFile($event): array
    {
        $response = ['success' => true, 'message' => '', 'detail' => []];

        try {
            $paymentsResponse = $this->getPayments($event);

            if (!empty($paymentsResponse['message']))
                return [
                    'success' => $paymentsResponse['success'],
                    'message' => $paymentsResponse['message'],
                    'detail' => []
                ];

            $this->setLogLine("Sending General file");
            $this->saveAndSentInterface(
                $this->singleFile($this->getDataPayment($paymentsResponse['payments'])),
                $this->getFilename(),
                'Payment'
            );


        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function getPayments($event): array
    {
        $this->setLogLine("Start Interface Payments process");

        if (!$this->init($event)) {
            return [
                'success' => true,
                'payments' => Collect([]),
                'message' => 'Interface not generated until configured day of this month'
            ];
        }

        $openingIds = $deposits = [];

        $countryReferenceCurrencies = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.country-reference-currencies.get_all'), [
                'country_id' => $this->countryId,
            ]);

        $this->countryReferenceCurrencies = Collect($countryReferenceCurrencies['country_reference_currencies']);
        $countryReferenceCurrencyIds = array_column($countryReferenceCurrencies['country_reference_currencies'], 'id');

        $this->setLogLine("Get Country Reference Currency ids " . implode(', ', $countryReferenceCurrencyIds));

        $locations = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.locations.get_all'), [
                'company_country_id' => $this->companyCountryId,
                'limit' => 1000
            ]);

        $this->locations = Collect($locations['locations']);
        $locationIds = array_column($locations['locations'], 'id');

        $this->setLogLine("Get location ids " . implode(', ', $locationIds));

        $closings = ApiClientFacade::setBaseUrl(config('experteam-crud.cash-operations.base_url'))
            ->post(config('experteam-crud.cash-operations.closing.get_all'), [
                'locationIds' => $locationIds,
                'startDateTime' => $this->start,
                'endDateTime' => $this->end
            ]);

        if (!isset($closings['closings'])) {
            $this->setLogLine("Bad response from Api Cash Operations");
            $this->setLogLine(json_encode($closings));
            return [
                'success' => false,
                'payments' => Collect([]),
                'message' => 'Bad response from Api Cash Operations'
            ];
        }

        if (count($closings['closings']) == 0) {
            $this->setLogLine("No closings from Api Cash Operations");
            return [
                'success' => true,
                'payments' => Collect([]),
                'message' => 'No closings from Api Cash Operations'
            ];
        }

        foreach ($closings['closings'] as $closing) {
            $tmpOpeningIds = array_column($closing['openings'], 'id');
            $openingIds = array_merge($openingIds, $tmpOpeningIds);

            $this->closing[] = [
                'id' => $closing['id'],
                'openingIds' => $openingIds,
            ];

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
            return [
                'success' => true,
                'payments' => Collect([]),
                'message' => 'No payments associated to given openings'
            ];
        }

        $countryPaymentTypesResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.invoices.base_url'))
            ->get(config('experteam-crud.invoices.country_payment_types.get_all'), [
                'country_id' => $this->countryId,
            ]);

        foreach ($countryPaymentTypesResponse['country_payment_types'] as $countryPaymentType) {
            if (is_null($countryPaymentType['code']))
                continue;

            $this->countryPaymentTypes[$countryPaymentType['code']] = $countryPaymentType['id'];
        }

        $this->setLogLine("Get Country Payment Type ids " . json_encode($this->countryPaymentTypes));

        return [
            'success' => true,
            'payments' => $payments,
            'message' => ''
        ];
    }

    private function getDataPayment(Collection $payments): ?array
    {
        if ($payments->count() == 0)
            return null;
        $result = [];
        $now = Carbon::now();

        foreach ($payments as $payment) {
            $paymentAmount = null;
            $shipmentTrackingNumber = null;
            $account = null;
            $location = $this->getLocation($payment['installation_id']);
            $locationCostCenter = LocationCostCenter::where('location_id', $location['id'])
                ->first();
            if (is_null($locationCostCenter)) {
                $this->setLogLine("No costCenter associated to location id: " . $location['id']);
                continue;
            }
            $costCenter = $locationCostCenter['cost_center'];
            $terminal = $locationCostCenter['terminal'];
            $paymentDueDate = Carbon::create($payment['due_date']);
            $paymentDueDate->getTimezone($location['gmt_offset']);
            $currencyCode = $this->getCountryReferenceCurrency($payment['country_reference_currency_id'])['currency']['code'];
            [$depositNumber, $bankAccount] = $this->getDepositNumber($payment);

            if (is_null($depositNumber))
                continue;

            foreach ($payment['documents'] as $document) {
                foreach ($this->getHeaderItems($document) as $item) {
                    [$paymentAmount, $account, $shipmentTrackingNumber] = $this->getDetailsItems($document, $item);
                }
            }
            $result[] = [
                'location_id' => $location['id'],
                'country_payment_type_id' => $payment['country_payment_type_id'],
                'date_payment' => $paymentDueDate->format('dmY'),
                'document_type' => $this->documentType,
                'company_code_sap' => $this->companyCodeSap,
                'date' => $now->format('dmY'),
                'month' => $now->format('m'),
                'currency_code' => $currencyCode,
                'cost_center' => $costCenter,
                'terminal' => $terminal,
                'deposit_number' => $depositNumber,
                'account' => $account,
                'payment_amount' => str_replace('.', '', $paymentAmount),
                'shipment_tracking_number' => $shipmentTrackingNumber,
                'client' => $this->client,
                'consolidated' => null,
                'bankAccount' => $bankAccount,
                'details' => $payment['details']
            ];
        }

        return $result;
    }

    public function singleFile($payments): string
    {
        $this->paymentData = Collect($payments);

        $fileContent = '';
        foreach ($this->locations as $location) {
            $payments = $this->paymentData->where('location_id', $location['id'])
                ->sortBy('country_payment_type_id');

            if (count($payments) == 0)
                continue;

            foreach ($this->countryPaymentTypes as $key => $countryPaymentType) {
                $paymentInterfaces = $payments->where('country_payment_type_id', $countryPaymentType);
                if (count($paymentInterfaces) == 0)
                    continue;

                $this->setLogLine("Get  Payment Interface Type  " . $key);

                $total = 0;
                $dateInfo = '';
                $costCenter = '';
                $depositNumber = '';
                $terminal = '';
                $account = '';
                $client = '';
                $bankAccount = '';

                foreach ($paymentInterfaces as $innerKey => $payment) {

                    $dateInfo = $payment['date_payment'] . $payment['document_type'] . $payment['company_code_sap'] . $payment['date'] . $payment['month'] . $payment['currency_code'];
                    $costCenter = $payment['cost_center'];
                    $depositNumber = $payment['deposit_number'];
                    $terminal = $payment['deposit_number'];
                    $account = $this->key . $payment['account'];
                    $amount = $payment['payment_amount'] . $payment['shipment_tracking_number'];
                    $client = $payment['client'];
                    $total .= $payment['payment_amount'];
                    $bankAccount = $this->keyConsolidated . $payment['bankAccount'];
                    $amount = $this->formatStringLength($amount, 24, true, "0");
                    $fileContent .= $this->formatLine($dateInfo, $costCenter, $depositNumber, $account, $amount, $client);

                    if (in_array($innerKey, ['CD', 'CC'])) {
                        $kind = $payment['country_payment_type_id'] == $this->countryPaymentTypes['CC'] ?
                            'credit' :
                            'debit';
                        $fields = Collect($payment['details']);
                        $typeCard = $fields->where('code', 'type_card')->first();
                        $typeCardCode = $this->typeCardCodes
                            ->where('kind', $kind)
                            ->where('type', $typeCard['value'])
                            ->first();

                        $bankAccount = $this->keyConsolidated . $typeCardCode['account'];
                        $terminal = $payment['terminal'];
                    }
                }

                $total = $this->formatStringLength($total, 13, true, "0");
                $fileContent .= $this->formatLineTotal($dateInfo, $costCenter, $terminal, $bankAccount, $total, $depositNumber, $client);
            }
        }

        $this->setLogLine("Get general file");

        return $fileContent;
    }

    public function getFilename(): string
    {
        return "glingesppp_CRA_" . Carbon::now()->format('Ymd') . Carbon::now()->format('His') . ".txt";
    }

    private function getDetailsItems($document, $item): array
    {
        $total = $item['total'];
        $account = null;
        $shipmentTrackingNumber = null;

        foreach ($document['items'] as $i) {
            if ($item['details']['header']['awbNumber'] == ($i['details']['relation'] ?? '')) {
                $total += (float)$i['total'];
                $account = $item['details']['header']['accountNumber'];
                $shipmentTrackingNumber = $item['details']['header']['awbNumber'];
            }
        }
        return [$total, $account, $shipmentTrackingNumber];
    }

    private function formatLine(string $dateInfo, string $costCenter, string $depositNumber, string $account, string $amount, string $clientType): string
    {
        return str_pad($dateInfo, 29) . str_pad($costCenter, 16) . str_pad($depositNumber, 25) . str_pad($account, 19) . str_pad($amount, 84)
            . $clientType
            . PHP_EOL;
    }

    private function formatLineTotal(string $dateInfo, string $costCenter, string $depositNumber, string $account, string $amount, string $number, string $clientType): string
    {
        return str_pad($dateInfo, 29) . str_pad($costCenter, 16) . str_pad($depositNumber, 25) . str_pad($account, 19) . str_pad($amount, 41)
            . str_pad($number, 43) . $clientType
            . PHP_EOL;
    }

    private function getCountryReferenceCurrency($id)
    {
        return $this->countryReferenceCurrencies->where('id', $id)->first();
    }

    private function getDepositNumber($payment): array
    {
        $depositNumber = null;
        $bankAccount = null;
        foreach ($this->deposits->all() as $deposit) {
            if (in_array($payment['opening_id'], $deposit['openingIds'])) {
                $depositNumber = $deposit['number'];
                if (isset($deposit['bankAccount']))
                    $bankAccount = $deposit['bankAccount']['sapNumber'];
            }
        }
        return [$depositNumber, $bankAccount];
    }
}
