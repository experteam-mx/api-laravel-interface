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

class InterfaceSaleReceiptListener extends InterfaceBaseListener
{

    public function getDocuments($event): array
    {
        $this->setLogLine("Start Interface Sales Receipts process");

        if (!$this->init($event)) {
            return [
                'success' => true,
                'documents' => Collect([]),
                'message' => 'Interface not generated until configured day of this month'
            ];
        }

        $locations = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.locations.get_all'), [
                'company_country_id' => $this->companyCountryId,
                'limit' => 1000
            ]);

        $this->locations = Collect($locations['locations']);

        $documents = InterfaceFacade::getDocumentsInvoices($this->start, $this->end, $this->companyCountryId, false);

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
                'SaleReceipts'
            );
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function getFileContent($documents): string
    {
        $documents = $this->getDataDocuments($documents);
        $fileContent = '';

        foreach ($documents as $document) {
            $amount = $this->formatStringLength($document['amount'], 12);
            $dateInfo = $document['type'] . $document['country_code'] . $document['location_code'] . $document['account'] . $document['date'] .
                $amount . $document['shipment_tracking_number'];

            $origin = $document['product_code'] . $document['origin'] . $document['destination'];
            $fileContent .= $this->formatLine(
                $dateInfo,
                $document['number_receipt'],
                $origin,
                $document['client'],
                $document['packages_count'],
                ($document['real_weight']) ?? null
            );
        }

        return $fileContent;
    }

    public function getFilename(): string
    {
        return $this->countryCode . "_WML_OC_02_" . Carbon::now()->format('Ymd') . "_" . Carbon::now()->format('His') . ".txt";
    }

    private function getDataDocuments(Collection $documents): ?array
    {
        $result = [];

        foreach ($documents as $document) {

            $location = $this->getLocation($document['document_type_range']['model_id']);
            $result = [];
            $shipment = $this->getHeaderItems($document);
            if (empty($shipment))
                continue;

            $shpCompanyCountryExtraCharges = $this->getShipmentItems($document, $shipment);
            $date = Carbon::create($document['created_at']);

            $ivaTotal = 0;
            $numberReceipt = $document['document_prefix'] . $document['document_number'];
            $shipmentTrackingNumber = $account = $productCode = $origin = $destination = $client = $realWeight = null;

            foreach ($shipment as $shp) {

                [$shipmentTrackingNumber, $account, $productCode, $origin, $destination, $client, $packagesCount, $realWeight] = $this->getDetailsItems($document, $shp);
                $iva = $this->getTaxIva($shp['tax_detail']);
                $ivaTotal += (float)$iva['tax_total'];

                $result[] = [
                    'type' => 'FT',
                    'country_code' => $this->country,
                    'location_code' => $location['location_code'],
                    'account' => $account,
                    'amount' => str_replace('.', '', $shp['subtotal']),
                    'date' => $date->format('dmy'),
                    'shipment_tracking_number' => $shipmentTrackingNumber,
                    'number_receipt' => $numberReceipt,
                    'product_code' => $productCode,
                    'origin' => $origin,
                    'destination' => $destination,
                    'client' => $client,
                    'packages_count' => $packagesCount,
                    'real_weight' => $realWeight,
                ];
            }

            if (!empty($shpCompanyCountryExtraCharges)) {

                foreach ($shpCompanyCountryExtraCharges as $shpCompanyCountryExtraCharge) {
                    $iva = $this->getTaxIva($shp['tax_detail']);
                    $ivaTotal += (float)$iva['tax_total'];

                    $result[] = [
                        'type' => $shpCompanyCountryExtraCharge['details']['code'],
                        'country_code' => $this->country,
                        'location_code' => $location['location_code'],
                        'account' => $account,
                        'amount' => str_replace('.', '', $shpCompanyCountryExtraCharge['subtotal']),
                        'date' => $date->format('dmy'),
                        'shipment_tracking_number' => $shipmentTrackingNumber,
                        'number_receipt' => $numberReceipt,
                        'product_code' => $productCode,
                        'origin' => $origin,
                        'destination' => $destination,
                        'client' => $client,
                        'packages_count' => null,
                        'real_weight' => null,
                    ];

                }
            }

            $result[] = [
                'type' => 'IV',
                'country_code' => $this->country,
                'location_code' => $location['location_code'],
                'account' => $account,
                'amount' => str_replace('.', '', $ivaTotal),
                'date' => $date->format('dmy'),
                'shipment_tracking_number' => $shipmentTrackingNumber,
                'number_receipt' => $numberReceipt,
                'product_code' => $productCode,
                'origin' => $origin,
                'destination' => $destination,
                'client' => $client,
                'packages_count' => null,
                'real_weight' => null,
            ];
        }

        return $result;
    }

    private function getTaxIva($tax): array
    {
        $items = Collect($tax);
        return $items->where('tax', 'IVA')
            ->first();
    }

    private function getDetailsItems($document, $item): array
    {
        $shipmentTrackingNumber = null;
        $account = null;
        $productCode = null;
        $origin = null;
        $destination = null;
        $client = null;
        $packagesCount = null;
        $realWeight = null;
        foreach ($document['items'] as $i) {
            if ($item['details']['header']['awbNumber'] == ($i['details']['relation'] ?? '')) {
                $productCode = $item['details']['code'];
                $shipmentTrackingNumber = $item['details']['header']['awbNumber'];
                $account = $item['details']['header']['accountNumber'];
                $origin = $item['details']['ticket_data']['origin_service_area_code'];
                $destination = $item['details']['ticket_data']['destination_service_area_code'];
                $client = $item['details']['ticket_data']['origin']['company_name'];
                $packagesCount = $item['details']['ticket_data']['packages_count'];
                $realWeight = $item['details']['ticket_data']['real_weight'];

            }
        }
        return [$shipmentTrackingNumber, $account, $productCode, $origin, $destination, $client, $packagesCount, $realWeight];
    }

    private function formatLine(
        string     $dateInfo,
        string     $numberReceipt,
        string     $origin,
        string     $client,
        int|null   $packagesCount,
        float|null $realWeight
    ): string
    {
        if (is_null($realWeight)) {
            return str_pad($dateInfo, 50) . str_pad($numberReceipt, 10) . str_pad($origin, 11)
                . str_pad($client, 107)
                . PHP_EOL;
        } else {
            return str_pad($dateInfo, 50) . str_pad($numberReceipt, 10) . str_pad($origin, 11)
                . str_pad($client, 76) . str_pad(1, 5)
                . str_pad($packagesCount, 5) . str_pad(number_format($realWeight, 2), 21)
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

}