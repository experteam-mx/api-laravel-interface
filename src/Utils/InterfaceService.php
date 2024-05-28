<?php

namespace Experteam\ApiLaravelInterface\Utils;

use Carbon\Carbon;
use function App\Utils\Collect;

class InterfaceService
{

    public function sendToInterface(
        $fileName,
        $fileContent,
        $route,
        \Illuminate\Filesystem\FilesystemAdapter $fileSystem,
    ): array
    {
        $status = false;
        $logs = $this->logLineFormat("File to load - $fileName");
        if (str_starts_with($route, '\\')) {
            $logs .= $this->logLineFormat("Replace all \ by / in route parameter");
            $route = str_replace('\\', '/', $route);
        } elseif (str_starts_with($route, '/')) {
            $logs .= $this->logLineFormat("Replace first / in route parameter");
            $route = substr($route, 1);
        }

        $filePath = $route . $fileName;
        try {
            $timestamp_start = time();
            $logs .= $this->logLineFormat("Watching existing files in the Interface before transfer...");

            $interface_files = $fileSystem->files($route);
            foreach ($interface_files as $cur_file) {
                $fileSize = 0;
                try {
                    $fileSize = $fileSystem->fileSize($cur_file);
                } catch (\League\Flysystem\FilesystemException $e) {
                    $logs .= $this->logLineFormat("File size read error!: " . $e->getMessage());
                }
                $logs .= $this->logLineFormat("Existing - $cur_file - size $fileSize");
                if ($filePath === $cur_file) {
                    $logs .= $this->logLineFormat("This file will be overwritten");
                }
            }

            $logs .= $this->logLineFormat("Initialization Transfer $filePath ...");

            $fileSystem->put($filePath, $fileContent);

            $logs .= $this->logLineFormat("Transfer Done!");

            $logs .= $this->logLineFormat("Watching existing files in the Interface after transfer...");

            $interface_files = $fileSystem->files($route);
            foreach ($interface_files as $cur_file) {
                $fileSize = 0;
                try {
                    $fileSize = $fileSystem->fileSize($cur_file);
                } catch (\League\Flysystem\FilesystemException $e) {
                    $logs .= $this->logLineFormat("File size read error!: " . $e->getMessage());
                }
                $logs .= $this->logLineFormat("Existing - $cur_file - size $fileSize");
            }

            $timestamp_end = time();
            $ProcessDelayTime = $timestamp_end - $timestamp_start;
            $delayFormatted = gmdate('H:i:s', $ProcessDelayTime);

            $status = true;

            $logs .= $this->logLineFormat("Process delay time: $delayFormatted");
        } catch (\Exception $e) {
            $logs .= $this->logLineFormat("Transfer Process Stopped!:");
            $logs .= $e . PHP_EOL;
        }

        return [$logs, $status];
    }

    public function getPaymentsInvoices($openingIds): \Illuminate\Support\Collection
    {
        $paymentsAll = [];
        $limit = 500;
        $offset = 0;
        $iteration = 500;

        do {
            $paymentResponse = \ApiInvoices::getAllPayments([
                'opening_id[in]' => implode(',', $openingIds),
                'status' => 2,
                'order' => ['id' => 'ASC'],
                'limit' => $limit,
                'offset' => $offset
            ]);

            $cur_payments = $paymentResponse['payments'];

            $paymentsAll = array_merge($paymentsAll, $cur_payments);

            $offset = $offset + $iteration;

        } while (count($paymentResponse['payments']) > 0 && $paymentResponse['payments'] == 500);

        return Collect($paymentsAll);
    }

    public function getDocumentsInvoices(string $startDate, string $finishDate, int $companyCountryId, bool $isBilling): \Illuminate\Support\Collection
    {

        $documentsAll = [];
        $limit = 500;
        $offset = 0;
        $iteration = 500;

        do {
            $documentResponse = \ApiInvoices::getAllDocumentsInterfaces([
                'start_date' => $startDate,
                'finish_date' => $finishDate,
                'company_country_id' => $companyCountryId,
                'is_billing' => $isBilling,
                'order' => ['id' => 'ASC'],
                'limit' => $limit,
                'offset' => $offset
            ]);

            $cur_documents = $documentResponse['documents'];
            $documentsAll = array_merge($documentsAll, $cur_documents);
            $offset = $offset + $iteration;

        } while (count($documentResponse['documents']) > 0 && $documentResponse['documents'] == 500);


        return Collect($documentsAll);
    }

    private function logLineFormat(string $line): string
    {
        return Carbon::now()->format('Y/m/d H:i:s') . " - " . $line . PHP_EOL;
    }

}
