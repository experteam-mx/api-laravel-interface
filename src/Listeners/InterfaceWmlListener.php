<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use App\Models\LocationCustomerAccount;
use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Experteam\ApiLaravelInterface\Models\Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfaceWmlListener extends InterfaceBaseListener
{

    public string $defaultCustomerAccount = "CASHEC001";
    public bool $getLocationAccount = true;

    public function getDocuments($event): array
    {
        $this->setLogLine("Start Interface WML process");

        if (!$this->init($event)) {
            return [
                'success' => true,
                'documents' => Collect([]),
                'message' => 'Interface not generated until configured day of this month'
            ];
        }

        $this->countryReference = json_decode(Redis::hget('companies.countryReference', $this->countryId), true);
        $this->currencyCode = json_decode(Redis::hget('catalogs.currency', $this->countryReference['currency_id']), true)['code'];
        $locations = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.locations.get_all'), [
                'company_country_id' => $this->companyCountryId,
                'limit' => 1000
            ]);

        $this->locations = Collect($locations['locations']);

        $documents = InterfaceFacade::getDocumentsInvoices($this->start, $this->end, $this->companyCountryId, true);

        if ($documents->count() == 0)
            return [
                'success' => true,
                'documents' => $documents,
                'message' => 'No Payment Receipts to sent'
            ];

        $this->setLogLine("Document generated correctly");

        return [
            'success' => true,
            'documents' => $documents,
            'message' => ''
        ];
    }

    public function processFile($event): array
    {
        $response = ['success' => true, 'message' => '', 'detail' => []];

        try {
            $documentsResponse = $this->getDocuments($event);

            if (!empty($documentsResponse['message']))
                return [
                    'success' => $documentsResponse['success'],
                    'message' => $documentsResponse['message'],
                    'detail' => []
                ];

            $this->setLogLine("Sending General file");
            $this->saveAndSentInterface(
                $this->getFileContent($documentsResponse['documents']),
                $this->getFilename(),
                'WML'
            );
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function getFileContent($documents, bool $useTax = false): string
    {
        $documents = $this->getDataDocuments($documents);
        $fileContent = '';

        foreach ($documents as $document) {

            $code = ($document['type'] == 'Product') ? '9F' : $document['code'];

            if ($document['amount'] < 0)
                $amount = '-' . $this->formatStringLength(($document['amount'] * -1), 11, true, '0');
            else
                $amount = $this->formatStringLength($document['amount'], 12, true, '0');

            $dateInfo = $code . $document['country_code'] . $document['location_code'] . $document['account'] .
                $amount . $document['date'] . $document['number_receipt'];

            $origin = $document['product_code'] . $document['origin'] . $document['destination'];

            if ($useTax) {
                $taxInfo = str_pad($document['tax_amount'], 13, '0', STR_PAD_LEFT) . str_pad($document['tax_percentage'], 5, '0', STR_PAD_LEFT) . $document['tax_code'];

            } else {
                $taxInfo = str_pad('', 20);
            }

            $fileContent .= $this->formatLine(
                $dateInfo,
                $document['shipment_tracking_number'],
                $origin,
                $document['client'],
                $document['packages_count'],
                ($document['real_weight']) ?? null,
                $taxInfo
            );
        }

        return $fileContent;
    }

    public function getFilename(): string
    {
        return $this->countryCode . "_WML_OC_02_" . Carbon::now()->format('Ymd') . "_" . Carbon::now()->format('His') . ".txt";
    }

    /**
     * @throws \Exception
     */
    public function getDataDocuments(Collection $documents): ?array
    {
        $result = [];
        foreach ($documents as $document) {
            $location = $this->getLocation($document['installation_id']);
            if (is_null($location))
                continue;

            $shipments = $this->getHeaderItems($document);
            if (empty($shipments))
                continue;

            $documentData = $this->prepareDocumentData($document);

            foreach ($shipments as $key => $shipment) {
                $shipmentData = $this->prepareShipmentData($shipment, $location);
                $receiptNumber = $this->generateReceiptNumber($document, $key, count($shipments));
                $taxData = $this->calculateTaxData($shipment['tax_detail'] ?? []);

                $result[] = $this->createProductEntry(
                    $documentData,
                    $shipmentData,
                    $receiptNumber,
                    $taxData
                );

                $extraCharges = $this->getShipmentItems($document, $shipment);
                if (!empty($extraCharges)) {
                    foreach ($extraCharges as $extraCharge) {
                        $extraChargeTaxData = $this->calculateTaxData($extraCharge['tax_detail'] ?? []);
                        $result[] = $this->createExtraChargeEntry(
                            $documentData,
                            $shipmentData,
                            $receiptNumber,
                            $extraCharge,
                            $extraChargeTaxData
                        );
                    }
                }
            }
        }
        return $result;
    }

    private function prepareDocumentData(array $document): array
    {
        return [
            'date' => Carbon::create($document['created_at'])->format('dmy'),
            'tax_exempt' => !empty($document['extra_fields']) && isset($document['extra_fields']['tax_exempt'])
        ];
    }

    private function prepareShipmentData(array $shipment, array $location): array
    {
        return [
            'product_code' => $shipment['details']['code'],
            'tracking_number' => $shipment['details']['header']['awbNumber'],
            'origin' => $shipment['details']['ticket_data']['origin_service_area_code'],
            'destination' => $shipment['details']['ticket_data']['destination_service_area_code'],
            'client' => Str::ascii(substr($shipment['details']['ticket_data']['origin']['company_name'], 0, 50)),
            'packages_count' => count($shipment['details']['header']['pieces']),
            'real_weight' => $shipment['details']['ticket_data']['real_weight'],
            'account' => $this->getAccount($shipment, $location),
            'location_code' => $location['location_code'],
            'total' =>  $shipment['total'],
            'subtotal' =>  $shipment['subtotal'] - ($shipment['discount'] ?? 0),
        ];
    }

    private function generateReceiptNumber(array $document, int $key, int $totalShipments): string
    {
        $baseNumber = $document['document_prefix'] . $document['document_number'];
        if ($totalShipments > 1) {
            return $baseNumber . '-' . chr(65 + $key);
        }
        return $baseNumber;
    }

    private function calculateTaxData(array $taxDetail): array
    {
        $taxData = ['total' => 0, 'base' => 0, 'percentage' => 0];
        if (!empty($taxDetail)) {
            $iva = $this->getTaxIva($taxDetail);
            if (!is_null($iva)) {
                $taxData = [
                    'total' => (float)$iva['tax_total'],
                    'base' => (float)$iva['base'],
                    'percentage' => (float)$iva['percentage']
                ];
            }
        }
        return $taxData;
    }

    private function createProductEntry(array $documentData, array $shipmentData, string $receiptNumber, array $taxData): array
    {
        return [
            'type' => 'Product',
            'code' => 'FT',
            'country_code' => $this->country,
            'location_code' => $shipmentData['location_code'],
            'account' => $shipmentData['account'],
            'amount_subtotal' => $this->formatNumber($shipmentData['subtotal']),
            'amount' => $this->formatNumber($shipmentData['total']),
            'date' => $documentData['date'],
            'shipment_tracking_number' => $shipmentData['tracking_number'],
            'number_receipt' => $receiptNumber,
            'product_code' => $shipmentData['product_code'],
            'origin' => $shipmentData['origin'],
            'destination' => $shipmentData['destination'],
            'client' => $shipmentData['client'],
            'packages_count' => $shipmentData['packages_count'],
            'real_weight' => $shipmentData['real_weight'],
            'tax_code' => 'D0',
            'tax_exempt' => $documentData['tax_exempt'],
            'tax_amount' => $this->formatNumber($taxData['total']),
            'tax_amount_subtotal' => $this->formatNumber($taxData['base']),
            'tax_percentage' => $this->formatNumber($taxData['percentage'])
        ];
    }

    protected function createExtraChargeEntry(array $documentData, array $shipmentData, string $receiptNumber, mixed $extraCharge, array $extraChargeTaxData): array
    {
        return [
            'type' => 'ExtraCharge',
            'code' => $extraCharge['details']['code'],
            'country_code' => $this->country,
            'location_code' => $shipmentData['location_code'],
            'account' => $shipmentData['account'],
            'amount_subtotal' => $this->formatNumber($extraCharge['subtotal'] - ($extraCharge['discount'] ?? 0)),
            'amount' =>$this->formatNumber($extraCharge['total']),
            'date' => $documentData['date'],
            'shipment_tracking_number' => $shipmentData['tracking_number'],
            'number_receipt' => $receiptNumber,
            'product_code' => $shipmentData['product_code'],
            'origin' => $shipmentData['origin'],
            'destination' => $shipmentData['destination'],
            'client' => $shipmentData['client'],
            'packages_count' => null,
            'real_weight' => null,
            'tax_code' => 'D0',
            'tax_exempt' => $documentData['tax_exempt'],
            'tax_amount' => $this->formatNumber($extraChargeTaxData['total']),
            'tax_amount_subtotal' => $this->formatNumber($extraChargeTaxData['base']),
            'tax_percentage' => $this->formatNumber($extraChargeTaxData['percentage'])
        ];
    }

    protected function getTaxIva($tax): ?array
    {
        $items = Collect($tax);
        return $items->where('tax', 'IVA')
            ->first();
    }

    public function formatLine(
        string      $dateInfo,
        string      $numberReceipt,
        string      $origin,
        string      $client,
        int|null    $packagesCount,
        float|null  $realWeight,
        string|null $taxInfo,
    ): string
    {
        if (is_null($realWeight)) {
            return str_pad($dateInfo, 50) . str_pad($numberReceipt, 10) . str_pad($origin, 11)
                . str_pad($client, 117)
                . $taxInfo
                . str_pad('', 220)
                . PHP_EOL;
        } else {
            return str_pad($dateInfo, 50) . str_pad($numberReceipt, 10) . str_pad($origin, 11)
                . str_pad($client, 81) . str_pad(1, 5)
                . str_pad($packagesCount, 5) . str_pad(number_format($realWeight, 2), 10)
                . str_pad($this->currencyCode, 5)
                . str_pad('1', 11)
                . $taxInfo
                . str_pad('', 220)
                . PHP_EOL;
        }
    }

    protected function getShipmentItems(array $document, mixed $headerItem): array
    {
        $items = [];
        foreach ($document['items'] as $item) {
            if (($item['details']['relation'] ?? '') == $headerItem['details']['header']['awbNumber'])
                $items[] = $item;
        }
        return $items;
    }

    public function getInterfaceFilesystem(): \Illuminate\Filesystem\FilesystemAdapter
    {
        if (is_null($this->interfaceFilesystem)) {
            $config = Config::query()
                ->where('code', 'INTERFACE_STORAGE_WML')
                ->first();

            $this->interfaceFilesystem = \Storage::build($config->value);
        }

        return $this->interfaceFilesystem;
    }

    public function getAccount($shipment, $location): string
    {
        if ($this->getLocationAccount) {
            $account = LocationCustomerAccount::where('location_code', $location['location_code'])
                ->first()?->customer_account ?? $this->defaultCustomerAccount;
        } else {
            $account = $shipment['details']['header']['accountNumber'] ?? $this->defaultCustomerAccount;
        }

        if (is_numeric($account))
            $account = str_pad($account, 9, '0', STR_PAD_LEFT);
        else
            $account = str_pad($account, 9);

        return $account;
    }

    public function formatNumber(mixed $number): string
    {
        return number_format((float)$number, 2, '', '');
    }
}
