<?php

namespace Experteam\ApiLaravelInterface\Commands;

use Experteam\ApiLaravelInterface\Models\InterfaceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

abstract class GenerateInterfacesCommand extends Command
{
    public int $startDayOfMonth = 1;

    public function getInterfaceRequest(?string $interfaceType = null): ?InterfaceRequest
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $toSftp = $this->option('toSftp');

        if (is_null($from) && is_null($to) && Carbon::now()->format('d') != 1 && $this->startDayOfMonth != 1) {

            if (Carbon::now()->format('d') < $this->startDayOfMonth) {
                return null;
            } elseif (Carbon::now()->format('d') == $this->startDayOfMonth) {
                $from = Carbon::now()->startOfMonth()->startOfDay();
                $to = Carbon::yesterday()->endOfDay();
            } else {
                $from = Carbon::yesterday()->startOfDay();
                $to = Carbon::yesterday()->endOfDay();
            }

        } else {
            $from = !is_null($from) ? Carbon::create($from) : Carbon::yesterday()->startOfDay();
            $to = !is_null($to) ? Carbon::create($to) : Carbon::yesterday()->endOfDay();
        }

        $toSftp = is_null($toSftp) || $toSftp == 'true';

        return InterfaceRequest::create([
            'from' => $from->format('Y-m-d H:m:s'),
            'to' => $to->format('Y-m-d H:m:s'),
            'transaction_id' => \Str::orderedUuid()->toString(),
            'status' => 0,
            'to_sftp' => $toSftp,
            'type' => $interfaceType,
        ]);
    }
}
