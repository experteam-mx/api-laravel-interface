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

    public function getDataDocuments(Collection $documents): ?array
    {
        $result = [];

        foreach ($documents as $document) {

            $location = $this->getLocation($document['installation_id']);
            if (is_null($location))
                continue;


            $account = LocationCustomerAccount::where('location_code', $location['location_code'])
                ->first()?->customer_account ?? $this->defaultCustomerAccount;

            if (is_numeric($account))
                $account = str_pad($account, 9, '0', STR_PAD_LEFT);
            else
                $account = str_pad($account, 9);


            $shipment = $this->getHeaderItems($document);
            if (empty($shipment))
                continue;

            $countShipment = count($shipment);
            $date = Carbon::create($document['created_at']);
            $numberReceipt = $document['document_prefix'] . $document['document_number'];
            $shipmentTrackingNumber = $productCode = $origin = $destination = $client = $realWeight = null;
            $taxExempt = (!empty($document['extra_fields']) && isset($document['extra_fields']['tax_exempt'])) ? true : false;

            foreach ($shipment as $key => $shp) {
                $ivaTotal = $ivaBase = $ivaPercentage = 0;
                if ($countShipment > 1) {
                    $numberInLetter = chr(65 + $key);
                    $numberReceipt = $document['document_prefix'] . $document['document_number'] . '-' . $numberInLetter;
                }

                $shpCompanyCountryExtraCharges = $this->getShipmentItems($document, $shp);
                $productCode = $shp['details']['code'];
                $shipmentTrackingNumber = $shp['details']['header']['awbNumber'];
                $origin = $shp['details']['ticket_data']['origin_service_area_code'];
                $destination = $shp['details']['ticket_data']['destination_service_area_code'];
                $client = Str::ascii(substr($shp['details']['ticket_data']['origin']['company_name'], 0, 50));
                $packagesCount = count($shp['details']['header']['pieces']);
                $realWeight = $shp['details']['ticket_data']['real_weight'];


                if (!empty($shp['tax_detail'])) {
                    $iva = $this->getTaxIva($shp['tax_detail']);
                    if (!is_null($iva)) {
                        $ivaTotal += (float)$iva['tax_total'];
                        $ivaBase += (float)$iva['base'];
                        $ivaPercentage += (float)$iva['percentage'];
                    }

                }

                $result[] = [
                    'type' => 'Product',
                    'code' => 'FT',
                    'country_code' => $this->country,
                    'location_code' => $location['location_code'],
                    'account' => $account,
                    'amount_subtotal' => str_replace('.', '', number_format($shp['subtotal'], 2, '.', '')),
                    'amount' => str_replace('.', '', number_format($shp['total'], 2, '.', '')),
                    'date' => $date->format('dmy'),
                    'shipment_tracking_number' => $shipmentTrackingNumber,
                    'number_receipt' => $numberReceipt,
                    'product_code' => $productCode,
                    'origin' => $origin,
                    'destination' => $destination,
                    'client' => $client,
                    'packages_count' => $packagesCount,
                    'real_weight' => $realWeight,
                    'tax_code' => 'D0',
                    'tax_exempt' => $taxExempt,
                    'tax_amount' => str_replace('.', '', number_format($ivaTotal, 2, '.', '')),
                    'tax_amount_subtotal' => str_replace('.', '', number_format($ivaBase, 2, '.', '')),
                    'tax_percentage' => str_replace('.', '', number_format($ivaPercentage, 2, '.', '')),
                ];

                if (!empty($shpCompanyCountryExtraCharges)) {

                    foreach ($shpCompanyCountryExtraCharges as $shpCompanyCountryExtraCharge) {
                        $ivaTotal = $ivaBase = $ivaPercentage = 0;
                        if (!empty($shp['tax_detail'])) {
                            $iva = $this->getTaxIva($shp['tax_detail']);
                            if (!is_null($iva)) {
                                $ivaTotal += (float)$iva['tax_total'];
                                $ivaBase += (float)$iva['base'];
                                $ivaPercentage += (float)$iva['percentage'];
                            }
                        }

                        $result[] = [
                            'type' => 'ExtraCharge',
                            'code' => $shpCompanyCountryExtraCharge['details']['code'],
                            'country_code' => $this->country,
                            'location_code' => $location['location_code'],
                            'account' => $account,
                            'amount_subtotal' => str_replace('.', '', number_format($shpCompanyCountryExtraCharge['subtotal'], 2, '.', '')),
                            'amount' => str_replace('.', '', number_format($shpCompanyCountryExtraCharge['total'], 2, '.', '')),
                            'date' => $date->format('dmy'),
                            'shipment_tracking_number' => $shipmentTrackingNumber,
                            'number_receipt' => $numberReceipt,
                            'product_code' => $productCode,
                            'origin' => $origin,
                            'destination' => $destination,
                            'client' => $client,
                            'packages_count' => null,
                            'real_weight' => null,
                            'tax_code' => 'D0',
                            'tax_exempt' => $taxExempt,
                            'tax_amount' => str_replace('.', '', number_format($ivaTotal, 2, '.', '')),
                            'tax_amount_subtotal' => str_replace('.', '', number_format($ivaBase, 2, '.', '')),
                            'tax_percentage' => str_replace('.', '', number_format($ivaPercentage, 2, '.', '')),
                        ];

                    }
                }
            }
        }


        return $result;
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

}
