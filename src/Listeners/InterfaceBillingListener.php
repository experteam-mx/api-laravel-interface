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

class InterfaceBillingListener extends InterfaceBaseListener
{

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

        $documents = InterfaceFacade::getDocumentsInvoices($this->start, $this->end, $this->companyCountryId, true);

        if ($documents->count() == 0)
            return [
                'success' => true,
                'documents' => $documents,
                'message' => 'No invoices to sent'
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
                'Billing'
            );
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function getFileContent($documents): string
    {
        $fileContent = '';
        foreach ($documents as $document) {
            foreach ($this->getHeaderItems($document) as $item) {
                $result = [
                    'account' => Str::limit($item['details']['header']['accountNumber'], 20, ''),
                    'shipment_tracking_number' => Str::limit($item['details']['header']['awbNumber'], 20, ''),
                    'invoice_number' => Str::limit($document['document_prefix'] . $document['document_number'] . $document['document_suffix'], 40, ''),
                    'customer_identification_number' => Str::limit($document['customer_identification_number'], 40, ''),
                ];

                $fileContent .= "{$result['account']}\t{$result['shipment_tracking_number']}\t{$result['invoice_number']}\t"
                . "{$result['customer_identification_number']}" . PHP_EOL;
            }
        }

        return $fileContent;
    }

    public function getFilename(): string
    {
        return $this->countryCode . "_PMNTREF_" . Carbon::now()->format('md') . "_CRA.txt";
    }
}
