<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use Experteam\ApiLaravelInterface\Models\Config;
use Experteam\ApiLaravelInterface\Models\InterfaceFile;
use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfacePaymentsBaseListener
{
    protected ?\Illuminate\Filesystem\FilesystemAdapter $interfaceFilesystem = null;
    protected string $country = "EC";
    protected Collection $locations;
    protected mixed $interfaceRequestId;
    protected mixed $toSftp;
    protected string $destFolder = "DO-FB01-IN/";

    private function getLocation($installationId)
    {
        $locationId = intval(json_decode(
            Redis::hget('companies.installation', $installationId)
        )?->location_id ?? 0);
        return $this->locations->where('id', $locationId)->first();

    }

    private function getUser($userId)
    {
        return json_decode(Redis::hget('security.user', $userId), true);
    }

    private function saveAndSentInterface(string $fileContent, string $fileName, string $type): void
    {
        \Storage::put("$type/$fileName", $fileContent);

        $interfaceFilesystem = $this->getInterfaceFilesystem();

        if ($this->toSftp) {
            [$transmissionOutput, $success] = InterfaceFacade::sendToInterface($fileName, $fileContent, $this->destFolder, $interfaceFilesystem);
        } else {
            $transmissionOutput = 'Do not sent interface';
            $success = 1;
        }

        InterfaceFile::create([
            'name' => $fileName,
            'interface_request_id' => $this->interfaceRequestId,
            'type' => $type,
            'transmission_output' => $transmissionOutput,
            'status' => $success ? 1 : 2,
        ]);

    }

    private function getHeaderItems($document): array
    {
        $items = Collect($document['items']);
        return array_values($items->where('model_origin', 'shipments')
            ->where('model_type', 'Shipment')
            ->all());
    }

    protected function setLogLine(string $message): void
    {
        Console::getOutput()->writeLine($message);
    }

    public function getInterfaceFilesystem(): \Illuminate\Filesystem\FilesystemAdapter
    {
        if (is_null($this->interfaceFilesystem)) {
            $config = Config::query()
                ->where('code', 'INTERFACE_STORAGE')
                ->first();

            $this->interfaceFilesystem = \Storage::build($config->value);
        }

        return $this->interfaceFilesystem;
    }

    public function getCompanyCountryCurrencies($companyCountryId): Collection
    {
        $companyCountryCurrencies = Redis::hgetall('companies.companyCountryCurrency');
        $companyCountryCurrencyList = [];
        foreach ($companyCountryCurrencies as $companyCountryCurrency) {
            $companyCountryCurrency = json_decode($companyCountryCurrency, true);
            if ($companyCountryCurrency['company_country_id'] == $companyCountryId) {
                $companyCountryCurrency['currency'] = json_decode(Redis::hget('catalogs.currency', $companyCountryCurrency['currency_id']), true);
                $companyCountryCurrencyList[] = $companyCountryCurrency;
            }
        }
        return Collect($companyCountryCurrencyList);
    }
}
