<?php

namespace Experteam\ApiLaravelInterface\Commands;

use Experteam\ApiLaravelBase\Facades\BusinessDaysFacade;
use Experteam\ApiLaravelCrud\Facades\ApiClientFacade;
use Experteam\ApiLaravelInterface\Models\InterfaceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

abstract class GenerateInterfacesCommand extends Command
{
    protected string $country = "";
    public int $startDayOfMonth = 1;
    public int $waitDays = 1;
    public array $bankReferenceInterfaceFiles = [];
    public array $notBankReferenceInterfaceFiles = InterfaceRequest::INTERFACE_FILE_TYPES;

    public function getInterfaceRequest(?string $interfaceType = null): ?InterfaceRequest
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $toSftp = $this->option('toSftp');

        if (!is_null($from) && !is_null($to)) {
            $range = [
                'from' => Carbon::create($from),
                'to' => Carbon::create($to)
            ];
        } else {
            $this->getParameters();
            $range = $this->waitStartOfTheMonth();
            if (is_null($range)) return null;
        }

        $toSftp = is_null($toSftp) || $toSftp == 'true';

        return InterfaceRequest::create([
            'from' => $range['from']->format('Y-m-d H:i:s'),
            'to' => $range['to']->format('Y-m-d H:i:s'),
            'transaction_id' => \Str::orderedUuid()->toString(),
            'status' => 0,
            'to_sftp' => $toSftp,
            'type' => $interfaceType,
        ]);
    }

    public function getPaymentInterfaceRequests(): ?array
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $toSftp = $this->option('toSftp');
        $toSftp = is_null($toSftp) || $toSftp == 'true';

        if (!is_null($from) && !is_null($to)) {
            return [InterfaceRequest::create([
                'from' => Carbon::create($from)->format('Y-m-d H:i:s'),
                'to' => Carbon::create($to)->format('Y-m-d H:i:s'),
                'transaction_id' => \Str::orderedUuid()->toString(),
                'status' => 0,
                'to_sftp' => $toSftp,
                'type' => null
            ])];
        }

        $this->getParameters();

        if (empty($this->bankReferenceInterfaceFiles)) {
            $range = $this->waitStartOfTheMonth();
            if (is_null($range)) return null;

            return [
                InterfaceRequest::create([
                    'from' => $range['from']->format('Y-m-d H:i:s'),
                    'to' => $range['to']->format('Y-m-d H:i:s'),
                    'transaction_id' => \Str::orderedUuid()->toString(),
                    'status' => 0,
                    'to_sftp' => $toSftp,
                    'type' => null,
                ])
            ];
        }

        $interfaceRequests = [
            InterfaceRequest::create([
                'from' => Carbon::yesterday()->startOfDay()->format('Y-m-d H:i:s'),
                'to' => Carbon::yesterday()->endOfDay()->format('Y-m-d H:i:s'),
                'transaction_id' => \Str::orderedUuid()->toString(),
                'status' => 0,
                'to_sftp' => $toSftp,
                'type' => null,
                'extras' => [
                    'interfaceFiles' => $this->notBankReferenceInterfaceFiles
                ],
            ])
        ];

        $range = $this->waitBankReference();

        if (!is_null($range) && !empty($this->bankReferenceInterfaceFiles)) {
            $interfaceRequests[] = InterfaceRequest::create([
                'from' => $range['from']->format('Y-m-d H:i:s'),
                'to' => $range['to']->format('Y-m-d H:i:s'),
                'transaction_id' => \Str::orderedUuid()->toString(),
                'status' => 0,
                'to_sftp' => $toSftp,
                'type' => null,
                'extras' => [
                    'interfaceFiles' => $this->bankReferenceInterfaceFiles
                ],
            ]);
        }

        return $interfaceRequests;
    }

    public function waitStartOfTheMonth(): ?array
    {
        if (Carbon::now()->format('d') < $this->startDayOfMonth) {
            return null;
        } elseif (Carbon::now()->format('d') == $this->startDayOfMonth && $this->startDayOfMonth != 1) {
            return [
                'from' => Carbon::yesterday()->startOfMonth()->startOfDay(),
                'to' => Carbon::yesterday()->endOfDay()
            ];
        }

        return [
            'from' => Carbon::yesterday()->startOfDay(),
            'to' => Carbon::yesterday()->endOfDay()
        ];
    }

    public function waitBankReference(): ?array
    {
        $dates = BusinessDaysFacade::getDays($this->waitDays);

        if (is_null($dates)) return null;
        return [
            'from' => Carbon::parse($dates['start'])->startOfDay(),
            'to' => Carbon::parse($dates['start'])->endOfDay()
        ];
    }

    public function getParameters(): void
    {
        if (empty($this->country)) return;
        $country = json_decode(Redis::hget('catalogs.country.code', $this->country), true);

        $parametersResponse = ApiClientFacade::setBaseUrl(config('experteam-crud.companies.base_url'))
            ->post(config('experteam-crud.companies.parameter_values.post'), [
                'parameters' => [
                    [
                        'name' => 'INTERFACES_BANK_REFERENCE_FILES',
                        'model_type' => 'Country',
                        'model_id' => $country['id']
                    ],
                    [
                        'name' => 'INTERFACES_BANK_REFERENCE_WAIT_DAYS',
                        'model_type' => 'Country',
                        'model_id' => $country['id']
                    ]
                ]
            ]);

        $this->getOutput()->writeln("Parameters response: " . json_encode($parametersResponse));

        foreach ($parametersResponse['parameters'] as $parameter) {
            if ($parameter['model_id'] != $country['id']) {
                continue;
            }

            switch ($parameter['name']) {
                case 'INTERFACES_BANK_REFERENCE_FILES':
                    if (!empty($parameter['value'])) {
                        $bankReferenceInterfaceFiles = [];
                        foreach ($parameter['value'] as $interfaceFile => $isActive) {
                            if ($isActive) {
                                $bankReferenceInterfaceFiles[] = $interfaceFile;
                            }
                        }
                        $this->bankReferenceInterfaceFiles = $bankReferenceInterfaceFiles;
                        $this->setNotBankReferenceInterfaceFiles();
                    }
                    break;
                case 'INTERFACES_BANK_REFERENCE_WAIT_DAYS':
                    $this->waitDays = $parameter['value'];
                    break;
                case 'INTERFACES_START_DAY_OF_MONTH':
                    $month = \Str::ucfirst(Carbon::now()->format('M'));
                    $this->startDayOfMonth = $parameter['value'][$month] ?? 1;
                    break;
            }
        }
    }

    public function setNotBankReferenceInterfaceFiles(): void
    {
        foreach ($this->bankReferenceInterfaceFiles as $interfaceFile) {
            $key = array_search($interfaceFile, $this->notBankReferenceInterfaceFiles, true);
            if ($key === false)
                continue;
            unset($this->notBankReferenceInterfaceFiles[$key]);
        }
    }
}
