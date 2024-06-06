<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use App\Events\OpeningBillingsEvent;
use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfaceBillingListener extends InterfacePaymentsBaseListener
{

    public function getDocuments($event): ?array
    {

        $this->setLogLine("Start Interface Billing process");

        $this->init($event);

        $documents = InterfaceFacade::getDocumentsInvoices($this->start, $this->end, $this->companyCountryId, true);

        $this->setLogLine("Document generated correctly");

        return $this->getDataBilling($documents);
    }

    public function getDataBilling(Collection $documents): array
    {
        $response = ['success' => true, 'message' => '', 'detail' => []];

        if ($documents->count() == 0)
            return ['success' => true, 'message' => 'No invoices to sent', 'detail' => []];

        $fileContent = '';

        $result = [];

        try {
            foreach ($documents as $document) {
                foreach ($this->getHeaderItems($document) as $item) {
                    $result[] = [
                        'account' => Str::limit($item['details']['header']['accountNumber'], 20, ''),
                        'shipment_tracking_number' => Str::limit($item['details']['header']['awbNumber'], 20, ''),
                        'invoice_number' => Str::limit($document['document_prefix'] . $document['document_number'] . $document['document_suffix'], 40, ''),
                        'customer_identification_number' => Str::limit($document['customer_identification_number'], 40, ''),
                    ];
                }
            }

            $this->setLogLine("Get general file");

            foreach ($result as $item) {
                $fileContent .= $this->formatBillingLine($item);
            }

            $this->setLogLine("Sending General file");
            $this->saveAndSentInterface(
                $fileContent,
                $this->countryCode . "_PMNTREF_" . Carbon::now()->format('md') . "_CRA.txt",
                'Billing'
            );
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getTrace()];
        }

        return $response;
    }

    public function formatBillingLine(array $result): string
    {
        return "{$result['account']}\t{$result['shipment_tracking_number']}\t{$result['invoice_number']}\t"
            . "{$result['customer_identification_number']}" . PHP_EOL;
    }
}
