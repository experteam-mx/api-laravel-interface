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

class InterfaceAutoBillingPaymentReferenceListener extends InterfaceBaseListener
{
    public function getDocuments($event): array
    {
        $this->setLogLine("Start Interface Auto Billing Payment Reference process");

        if (!$this->init($event)) {
            return [
                'success' => true,
                'documents' => Collect([]),
                'message' => 'Interface not generated until configured day of this month'
            ];
        }

        $documents = InterfaceFacade::getDocumentsInvoices($this->start, $this->end, $this->companyCountryId, true);

        if ($documents->count() > 0) {
            $documents = $documents->toArray();
            $filteredDocuments = [];
            foreach ($documents as $key => $document) {
                if (collect($document['items'] ?? [])->contains('model_origin', 'etoolsAutoBilling')
                    && $document['document_type_code'] != 'CRN') {
                    $filteredDocuments[] = $document;
                }
            }

            $documents = collect(array_values($filteredDocuments));
        }

        if ($documents->count() == 0) {
            return [
                'success' => true,
                'documents' => $documents,
                'message' => 'No Auto Billing Payment Reference Documents to sent'
            ];
        }

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

            if (!empty($documentsResponse['message'])) {
                return [
                    'success' => $documentsResponse['success'],
                    'message' => $documentsResponse['message'],
                    'detail' => []
                ];
            }

            $this->setLogLine("Sending General file");
            $this->saveAndSentInterface(
                $this->getFileContent($documentsResponse['documents']),
                $this->getFilename(),
                'AutoBilling Payment Reference'
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
            $fileContent .= $this->formatLine($document);
        }

        return $fileContent;
    }

    public function getFilename(): string
    {
        return $this->countryCode . "_PMNTREF_" . Carbon::now()->format('YmdHis') . ".txt";
    }

    public function getInterfaceFilesystem(): \Illuminate\Filesystem\FilesystemAdapter
    {
        if (is_null($this->interfaceFilesystem)) {
            $config = Config::query()
                ->where('code', 'INTERFACE_STORAGE_PAYMENT_REF')
                ->first();

            $this->interfaceFilesystem = \Storage::build($config->value);
        }

        return $this->interfaceFilesystem;
    }

    public function getAccount($item): string
    {
        $account = $item['details']['header']['accountNumber'];
        if (is_numeric($account)) {
            $account = str_pad($account, 9, '0', STR_PAD_LEFT);
        } else {
            $account = str_pad($account, 9);
        }

        return $account;
    }

    public function formatLine($document): string
    {
        $item = $this->getHeaderItems($document)[0];
        return $this->getAccount($item) . "\t" .
            $item['details']['header']['awbNumber'] . "\t" .
            $document['document_prefix'] . $document['document_number'] . $document['document_suffix'] . PHP_EOL;
    }
}
