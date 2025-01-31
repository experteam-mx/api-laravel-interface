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

class InterfaceBillingUUIDListener extends InterfaceBaseListener
{
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
                $this->singleFile($this->getDataBilling($documentsResponse['documents'])),
                $this->getFilename(),
                'Billing'
            );

        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function getDocuments($event): array
    {
        $this->setLogLine("Start Interface Billing process");

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

        $documents = InterfaceFacade::getDocumentsInvoices($this->start, $this->end, $this->companyCountryId, true);

        if ($documents->count() == 0) {
            $this->setLogLine("No Documents associated to given openings");

            return [
                'success' => true,
                'documents' => Collect([]),
                'message' => 'No Documents associated to given openings'
            ];
        }

        return [
            'success' => true,
            'documents' => $documents,
            'message' => ''
        ];
    }

    private function getDataBilling(Collection $documents): ?array
    {
        if ($documents->count() == 0)
            return null;
        $result = [];
        foreach ($documents as $document) {

            $shipmentTrackingNumber = null;
            $shipmentTrackingDate = null;
            $location = $this->getLocation($document['installation_id']);

            $billingDate = Carbon::create($document['created_at']);
            foreach ($this->getHeaderItems($document) as $item) {
                $shipmentTrackingNumber = $item['details']['header']['awbNumber'];
                $shipmentTrackingDate = $item['details']['ticket_data']['created_at'];

                $shipmentTrackingDate = Carbon::create($shipmentTrackingDate);
                $result[] = [
                    'location_id' => $location['id'],
                    'date_billing' => $billingDate->format('dmY'),
                    'date' => $shipmentTrackingDate->format('dmY'),
                    'year' => $shipmentTrackingDate->format('Y'),
                    'company_code_sap' => $this->companyCodeSap,
                    'shipment_tracking_number' => $shipmentTrackingNumber,
                    'uuid' => (isset($document['extra_fields']['uuid'])) ? $document['extra_fields']['uuid'] : null,
                ];
            }
        }

        return $result;
    }

    private function singleFile($documents): string
    {
        $this->documentsData = Collect($documents);

        $fileContent = '';
        foreach ($this->locations as $location) {
            $documentInterfaces = $this->documentsData->where('location_id', $location['id']);

            if (count($documentInterfaces) == 0)
                continue;

            foreach ($documentInterfaces as $document) {

                $fileContent .= $this->formatLine(
                    $document['company_code_sap'],
                    $document['year'],
                    $document['date_billing'],
                    $document['shipment_tracking_number'],
                    $document['uuid']
                );
            }
        }

        $this->setLogLine("Get general file");

        return $fileContent;
    }

    public function getFilename(): string
    {
        return $this->companyCodeSap . "_UUID_CRA_" . Carbon::now()->format('Ymd') . "_" . Carbon::now()->format('His') . ".txt";
    }

    private function formatLine(string $companyCodeSap, string $year, string $date, string $shipmentTrackingNumber, ?string $billingAuthorization): string
    {
        return $companyCodeSap . "|" . $year . "|" . $date . "|" . $shipmentTrackingNumber . "|" . $shipmentTrackingNumber . "|" . $billingAuthorization . "|"
            . PHP_EOL;
    }

    protected function getHeaderItems($document): array
    {
        $items = Collect($document['items']);
        return $items->where('model_origin', 'shipments')
            ->where('model_type', 'Shipment')
            ->all();
    }
}
