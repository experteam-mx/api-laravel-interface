<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfaceBillingGBIListener extends InterfaceBillingListener
{
    protected string $currencyCode = '';
    protected string $weightUnit = '';
    protected bool $hasTaxKey = false;
    protected bool $extraChargeSpecialCodes = false;
    protected bool $packagingExtraChargeSpecialCode = false;
    protected bool $hasPostalCharge = false;
    protected array $countryReference = [];
    private array $products = [];
    private array $installations = [];
    private array $regions = [];

    public function getFileContent($documents): string
    {
        $fileContent = '';
        foreach ($documents as $document) {
            $documentCounts = count($this->getHeaderItems($document));
            foreach ($this->getHeaderItems($document) as $item) {
                $fileContent .= $this->getHeaderLine($document, $item);
                $fileContent .= $this->getDetailLines($documentCounts, $document, $item);
            }
        }

        return $fileContent;
    }

    protected function init($event): bool
    {
        if (!parent::init($event))
            return false;

        $this->countryReference = json_decode(Redis::hget('companies.countryReference', $this->countryId), true);
        $this->currencyCode = json_decode(Redis::hget('catalogs.currency', $this->countryReference['currency_id']), true)['code'];

        $country = json_decode(Redis::hget('catalogs.country.code', $this->country), true);
        $this->weightUnit = strtoupper($country['unit']['weight']);

        $locations = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.locations.get_all'), [
                'company_country_id' => $this->companyCountryId,
                'limit' => 1000
            ]);

        $this->locations = Collect($locations['locations']);

        return true;
    }

    public function getFilename(): string
    {
        return "gbi_{$this->country}_exp_" . Carbon::now()->format('Ymdhis') . "_CRA.txt";
    }

    private function getHeaderLine(array $document, array $item): string
    {
        $exchange = $this->formatStringLength(
            number_format($document['exchange'], 4, '.', ''),
            9,
            true
        );

        $currentDate = Carbon::now()->format('Ymd');
        $DocumentDate = Carbon::parse($document['created_at'])->format('Ymd');
        $ShipmentDate = Carbon::parse($item['details']['ticket_data']['created_at'])->format('Ymd');

        $oneTimeAccount = $this->formatStringLength($this->getOneTimeAccount($document), 10);

        $product = $this->formatStringLength(
            $this->getProduct($item['details']['ticket_data']['product_id'] ?? 0)['localCode'],
            5);

        $piecesCount = $this->formatStringLength(
            number_format($item['details']['ticket_data']['packages_count'], 3, '.', ''),
            15, true);

        $customerCompanyName = $this->formatStringLength(
            $document['customer_company_name'],
            35);

        $customerContactName = $this->formatStringLength(
            $document['customer_full_name'],
            35);

        $customerZipcode = $this->formatStringLength(
            $document['customer_postal_code'] ?? '0',
            10);

        $location = $this->getLocation($document['installation_id']);

        $locationCity = $this->formatStringLength(
            $location['city_name'] ?? '',
            35);

        $suburb = $this->formatStringLength(
            '.',
            35);

        $customerAddress = $this->formatStringLength(
            $document['customer_address_line1'] ?? ' ',
            35);

        $regionCode = $this->formatStringLength(
            $this->getRegion($location['country_region_id'] ?? 0)['code'] ?? '01',
            3);

        $stationCode = $this->formatStringLength($this->getStationCode($location), 10);

        $salesOffice = $this->formatStringLength($this->getSalesOffice($location), 4);

        $realWeight = $this->formatStringLength(
            number_format($item['details']['ticket_data']['real_weight'], 4, '.', ''),
            15,
            true
        );

        $invoicedWeight = $this->formatStringLength(
            number_format(max($item['details']['ticket_data']['real_weight'], $item['details']['ticket_data']['volumetric_weight']), 4, '.', ''),
            15,
            true
        );

        $weightUnit = $this->formatStringLength($this->weightUnit, 3);

        $expressCenter = $this->formatStringLength($location['service_area_code'], 5);

        $filler = $this->formatStringLength('', 16);
        $invoiceNumber = $this->formatStringLength($document['document_prefix'] . $document['document_number'] . $document['document_suffix'], 16);
        $trackingNumber = $this->formatStringLength($item['details']['header']['awbNumber'], 18);

        $taxImport = $this->formatStringLength('1', 15);
        $buyRequest = $this->formatStringLength(' ', 20);
        $countryIdNumber = $this->formatStringLength(' ', 2);

        $originSAC = $this->formatStringLength($item['details']['ticket_data']['origin_service_area_code'], 4);
        $destinationSAC = $this->formatStringLength($item['details']['ticket_data']['destination_service_area_code'], 4);

        $taxId = !$this->hasTaxKey ? $this->formatStringLength($this->getTaxId($document), 16) : $this->formatStringLength(' ', 16);
        $secondFiller = $this->formatStringLength(' ', 50);
        $taxKey = $this->hasTaxKey ? $this->formatStringLength($this->getTaxId($document), 16,  true) : '';

        return "A001$this->currencyCode  1010ZFX5   1.0000$currentDate$DocumentDate$ShipmentDate" .
            "$oneTimeAccount$oneTimeAccount$oneTimeAccount$oneTimeAccount$product$trackingNumber  " .
            "$piecesCount$customerCompanyName$customerContactName$customerZipcode$this->country " .
            "$locationCity$suburb$customerAddress$regionCode$ShipmentDate $stationCode$salesOffice" .
            "$realWeight$invoicedWeight$weightUnit$expressCenter$filler$invoiceNumber$trackingNumber" .
            "$taxImport$buyRequest{$countryIdNumber}Z100$originSAC$destinationSAC$taxId$secondFiller" .
            "$taxKey" . PHP_EOL;
    }

    protected function getOneTimeAccount($document): string
    {
        $location = $this->getLocation($document['installation_id']);
        return '4600';
    }

    protected function getProduct($id): array
    {
        if (empty($this->products[$id])) {
            $this->products[$id] = json_decode(Redis::hget('catalogs.product', $id), true);
        }
        return $this->products[$id];
    }

    protected function getLocation($installationId): array
    {
        if (empty($this->installations[$installationId])) {
            $this->installations[$installationId] = json_decode(Redis::hget('companies.installation', $installationId), true);
        }
        return $this->locations->where('id', $this->installations[$installationId]['location_id'])->first();
    }

    protected function getRegion($regionId): array
    {
        if (empty($this->regions[$regionId])) {
            $this->regions[$regionId] = json_decode(Redis::hget('catalogs.region', $regionId), true);
        }
        return $this->regions[$regionId];
    }

    protected function getStationCode(array $location): string
    {
        return $this->country . '1' . $location['service_area_code'];
    }

    protected function getSalesOffice(array $location): string
    {
        return $location['service_area_code'];
    }

    protected function getTaxId(array $document): string
    {
        return $document['customer_identification_number'];
    }

    private function getDetailLines(int $documentCounts, array $document, mixed $headerItem): string
    {
        $invoiceNumber = $this->formatStringLength($document['document_prefix'] . $document['document_number'] . $document['document_suffix'], 16);
        $trackingNumber = $this->formatStringLength($headerItem['details']['header']['awbNumber'], 20);
        $filler = $this->formatStringLength('1', 10);
        $documentCounts = \Str::padRight($documentCounts, 5, '0');

        if ($document['status'] == 2) {
            $zero = $this->formatStringLength(
                number_format(0, 2, '.', ''),
                11, true);
            return "B$invoiceNumber$trackingNumber$filler{$documentCounts}ZR01$zero    1  $zero" . PHP_EOL;
        }

        $subtotal = $this->formatStringLength(
            number_format($headerItem['subtotal'], 2, '.', ''),
            11, true);

        $postalTax = 0;
        $taxes = array_sum(array_map(fn ($tax) => $tax['tax_total'],$headerItem['tax_detail'] ?? []));
        $taxPercentage = $headerItem['tax_detail'][0]['percentage'] ?? 0;
        $base = $headerItem['tax_detail'][0]['base'] ?? 0;

        $lines = "B$invoiceNumber$trackingNumber$filler{$documentCounts}ZR01$subtotal    1  $subtotal" . PHP_EOL;

        if ($this->hasPostalCharge) {
            $postalTax = $base;
        }

        foreach ($this->getShipmentItems($document, $headerItem) as $item) {
            $subtotal = $this->formatStringLength(
                number_format($item['subtotal'], 2, '.', ''),
                11, true);

            $code = $this->getExtraChargeCode($item['details']['code']);

            $taxes += array_sum(array_map(fn ($tax) => $tax['tax_total'],$item['tax_detail'] ?? []));
            $base += $item['tax_detail'][0]['base'] ?? 0;

            $lines .= "B$invoiceNumber$trackingNumber$filler$documentCounts$code$subtotal    1  $subtotal" . PHP_EOL;
        }

        $base = $taxes == 0 ? 0 : $base;

        if ($this->hasPostalCharge) {
            $taxPercentage = $postalTax;
            $taxes = $postalTax;
            $base = $postalTax;
        }

        $taxPercentage = $this->formatStringLength(
            number_format($taxPercentage, 2, '.', ''),
            11, true);

        $taxes = $this->formatStringLength(
            number_format($taxes, 2, '.', ''),
            13, true);

        $base = $this->formatStringLength(
            number_format($base, 2, '.', ''),
            15, true);

        $lines .= "B$invoiceNumber$trackingNumber$filler{$documentCounts}ZJ02$taxPercentage    1$taxes$base" . PHP_EOL;

        return $lines;
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

    protected function getExtraChargeCode(string $code): string
    {
        return match ($code) {
            'FF' => 'ZS23',
            'II' => 'ZS53',
            'YI', 'YJ', 'YK' => 'ZTDX',
            'CA', 'CB' => 'ZS28',
            'XX', 'XK' => $this->extraChargeSpecialCodes ? 'ZS44' : 'ZS40',
            'GG' => $this->packagingExtraChargeSpecialCode ? 'ZS47' : 'ZS_G',
            default => 'ZS44'
        };
    }
}
