<?php

namespace Experteam\ApiLaravelInterface\Listeners;

use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Experteam\ApiLaravelInterface\Facades\InterfaceFacade;
use Experteam\ApiLaravelInterface\Models\Config;
use Experteam\ApiLaravelInterface\Models\InterfaceFile;
use Experteam\ApiLaravelInterface\Models\InterfaceRequest;
use http\Env;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psy\Readline\Hoa\Console;

class InterfaceBaseListener
{
    protected ?\Illuminate\Filesystem\FilesystemAdapter $interfaceFilesystem = null;
    protected string $country = "EC";
    public ?string $countryGmtOffset = "-05:00";
    public string $countryCode = "EC10";
    protected Collection $locations;
    protected mixed $interfaceRequestId;
    protected mixed $toSftp;
    protected string $destFolder = "";
    protected int $countryId = 0;
    protected int $companyCountryId = 0;
    protected string $start = "";
    protected string $end = "";
    protected bool $saveFileOnDB = false;
    protected bool $sentEmail = false;
    protected array $emailsOnFail = ['crasupport@experteam.com.ec'];
    protected array $emailsOnSuccess = ['crasupport@experteam.com.ec'];
    private string $logLine = "";

    protected function init($event): bool
    {
        if (is_null($event->interfaceRequest)) {
            $this->setLogLine("Interface not generated until configured day of this month");
            return false;
        }
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
        $this->start = $this->getDatetimeGmt($start)->format('Y-m-d H:i:s');

        $this->end = $this->getDatetimeGmt($end)->format('Y-m-d H:i:s');

        $companyCountries = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->get(config('experteam-crud.companies.company-countries.get_all'), [
                'country_id' => $this->countryId,
                'company@name' => $event->company ?? 'DHL'
            ]);

        $this->companyCountryId = $companyCountries['company_countries'][0]['id'];

        $this->setLogLine("Get company country id " . $this->companyCountryId);

        return true;
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
            $transmissionOutput = 'Interface not sent';
            $success = 1;
        }

        InterfaceFile::create([
            'name' => $fileName,
            'interface_request_id' => $this->interfaceRequestId,
            'type' => $type,
            'file_content' => $this->saveFileOnDB ? $fileContent : null,
            'destination_folder' => $this->destFolder,
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

    protected function setLogLine(?string $message): void
    {
        $this->logLine .= $message ? "\n$message" : '';

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

    protected function getDatetimeGmt(Carbon $datetime): Carbon
    {
        $spanArray = explode(':', $this->countryGmtOffset);
        $minutes = ((int)($spanArray[0]) * 60) + ((int)($spanArray[1] ?? 0));
        return $datetime->subMinutes($minutes);
    }

    public function finishInterfaceRequest(
        ?InterfaceRequest $interfaceRequest,
        int               $status,
        string            $message,
        array             $detail
    ): void
    {
        if (is_null($interfaceRequest))
            return;

        if ($status == 1) {
            $interfaceRequest->update(['status' => 1]);
        } else {
            $this->setLogLine("Error: $message");
            $interfaceRequest->update([
                'status' => $status,
                'message' => $message,
                'detail' => $detail,
            ]);
        }

        if ($this->sentEmail)
            $this->sentEmail($interfaceRequest);
    }

    public function formatStringLength(string $string, int $length, bool $left = false): string
    {
        return str_pad(substr(Str::ascii($string), 0, $length), $length, ' ', $left ? STR_PAD_LEFT : STR_PAD_RIGHT);
    }

    public function sentEmail(InterfaceRequest $interfaceRequest): void
    {
        $from = Carbon::parse($interfaceRequest->from)->format('Y-m-d');
        $to = Carbon::parse($interfaceRequest->to)->format('Y-m-d');

        $env = config('app.env');
        $interfaceRangeStr = "generadas desde $from hasta $to";

        if ($interfaceRequest->status == 1) {
            $subject = "Interfaces SAP $this->country $env $interfaceRangeStr";
            $body = "Interfaces SAP $this->country $interfaceRangeStr";
            $attachments = $this->getEmailFiles($interfaceRequest);
            $destinations = $this->formatEmails($this->emailsOnSuccess);
        } else {
            $subject = "Error en las Interfaces SAP $this->country $env $interfaceRangeStr";
            $body = "Error en las Interfaces SAP $this->country $interfaceRangeStr:" .
                " $interfaceRequest->message";

            $interfaceRequest->refresh();

            $attachments = [
                [
                    'content' => base64_encode(json_encode($interfaceRequest->detail, JSON_PRETTY_PRINT)),
                    'name' => 'errorDetail.json',
                    'contentType' => 'application/json',
                    'embed' => false,
                ]
            ];
            $destinations = $this->formatEmails($this->emailsOnFail);
        }

        $body .= InterfaceFacade::getFormatedLog($this->logLine);

        ApiClientFacade::setBaseUrl(config('experteam-crud.services.base_url'))
            ->post(config('experteam-crud.services.emails'), [
                'destinations' => $destinations,
                'subject' => $subject,
                'template' => 'internal',
                'body' => $body,
                'attachments' => $attachments
            ]);
    }

    public function getEmailFiles(InterfaceRequest $interfaceRequest): array
    {
        $attachments = [];

        /** @var InterfaceFile $interfaceFile */
        foreach ($interfaceRequest->interfaceFiles()->get() as $interfaceFile) {
            $attachments[] = [
                'content' => base64_encode($interfaceFile->file_content),
                'name' => $interfaceFile->name,
                'contentType' => 'text/plain',
                'embed' => false,
            ];

            $attachments[] = [
                'content' => base64_encode($interfaceFile->transmission_output),
                'name' => $interfaceFile->name . '.log',
                'contentType' => 'text/plain',
                'embed' => false,
            ];
        }

        return $attachments;
    }

    public function formatEmails(array $emails): array
    {
        $destinations = [];
        foreach ($emails as $email) {
            $destinations[] = [
                'address' => $email,
                'name' => ''
            ];
        }
        return $destinations;
    }
}
