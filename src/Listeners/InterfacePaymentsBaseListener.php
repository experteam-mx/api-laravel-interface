<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Experteam\ApiLaravelInterface\Models\Config;
use Experteam\ApiLaravelInterface\Models\InterfaceFile;
use Experteam\ApiLaravelInterface\Models\InterfaceRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfacePaymentsBaseListener
{
    protected ?\Illuminate\Filesystem\FilesystemAdapter $interfaceFilesystem = null;
    protected string $country = "EC";
    public ?string $countryGmtOffset = "-05:00";
    public string $countryCode = "EC10";
    protected Collection $locations;
    protected mixed $interfaceRequestId;
    protected mixed $toSftp;
    protected string $destFolder = "DO-FB01-IN/";
    protected int $countryId = 0;
    protected int $companyCountryId = 0;
    protected string $start = "";
    protected string $end = "";

    protected function init($event)
    {
        $this->interfaceRequestId = $event->interfaceRequest->id;
        $this->toSftp = $event->interfaceRequest->to_sftp;

        $to = is_null($event->interfaceRequest->to) ?
            Carbon::yesterday()->startOfDay() :
            Carbon::create($event->interfaceRequest->to);
        $from = is_null($event->interfaceRequest->from) ?
            Carbon::yesterday()->endOfDay() :
            Carbon::create($event->interfaceRequest->from);
        $start = clone $from;
        $end = clone $to;

        $country = json_decode(Redis::hget('catalogs.country.code', $this->country), true);
        $this->countryId = $country['id'];

        $this->setLogLine("Get " . $this->country . " country id " . $this->countryId);

        $this->countryGmtOffset = $country['timezone'];

        $this->setLogLine("Interface required from " . $from->format('Y-m-d') . " to " . $to->format('Y-m-d'));
        $this->start = $this->getDatetimeString($start);

        $this->end = $this->getDatetimeString($end);

        $companyCountries = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.company-countries.get_all'), [
                'country_id' => $this->countryId,
                'company@name' => $event->company ?? 'DHL'
            ]);

        $this->companyCountryId = $companyCountries['company_countries'][0]['id'];

        $this->setLogLine("Get company country id " . $this->companyCountryId);

    }

    protected function getLocation($installationId)
    {
        $locationId = intval(json_decode(
            Redis::hget('companies.installation', $installationId)
        )?->location_id ?? 0);
        return $this->locations->where('id', $locationId)->first();

    }

    protected function getUser($userId)
    {
        return json_decode(Redis::hget('security.user', $userId), true);
    }

    protected function saveAndSentInterface(string $fileContent, string $fileName, string $type): void
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

    protected function getHeaderItems($document): array
    {
        $items = Collect($document['items']);
        return array_values($items->where('model_type', 'Shipment')
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

    protected function getDatetimeString(Carbon $datetime): string
    {
        $spanArray = explode(':', $this->countryGmtOffset);
        $minutes = ((int)($spanArray[0]) * 60) + ((int)($spanArray[1] ?? 0));
        return $datetime->addMinutes($minutes)->format('Y-m-d H:i:s');
    }

    public function finishInterfaceRequest(
        InterfaceRequest $interfaceRequest,
        int              $status,
        string           $message,
        array            $detail
    ): void
    {
        if ($status == 1) {
            $interfaceRequest->update(['status' => 1]);
        } else {
            $interfaceRequest->update([
                'status' => $status,
                'message' => $message,
                'detail' => $detail,
            ]);
        }
    }
}
