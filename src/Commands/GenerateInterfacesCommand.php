<?php

namespace Experteam\ApiLaravelInterface\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

abstract class GenerateInterfacesCommand extends Command
{
    public int $startDayOfMonth = 0;

    public function getInterfaceOptions(?string $interfaceType = null): false|array
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $toSftp = $this->option('toSftp');

        if (is_null($from) && is_null($to) && $this->startDayOfMonth > Carbon::now()->format('d')) {
            return false;
        } elseif (is_null($from) && is_null($to) && $this->startDayOfMonth == Carbon::now()->format('d')) {
            $from = Carbon::now()->startOfMonth()->startOfDay();
            $to = Carbon::yesterday()->endOfDay();
        } else {
            $from = !is_null($from) ? Carbon::create($from) : Carbon::yesterday()->startOfDay();
            $to = !is_null($to) ? Carbon::create($to) : Carbon::yesterday()->endOfDay();
        }

        $toSftp = is_null($toSftp) || $toSftp == 'true';

        return [
            'from' => $from->format('Y-m-d H:m:s'),
            'to' => $to->format('Y-m-d H:m:s'),
            'transaction_id' => \Str::orderedUuid()->toString(),
            'status' => 0,
            'to_sftp' => $toSftp,
            'type' => $interfaceType,
        ];
    }
}
