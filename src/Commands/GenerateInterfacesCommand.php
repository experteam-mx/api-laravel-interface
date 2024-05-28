<?php

namespace Experteam\ApiLaravelInterface\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

abstract class GenerateInterfacesCommand extends Command
{

    public function getOptions(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $toSftp = $this->option('toSftp');

        $from = !is_null($from) ? Carbon::create($from) : Carbon::yesterday()->startOfDay();
        $to = !is_null($to) ? Carbon::create($to) : Carbon::yesterday()->endOfDay();
        $toSftp = is_null($toSftp) || $toSftp == 'true';

        return [
            'from' => $from->format('Y-m-d H:m:s'),
            'to' => $to->format('Y-m-d H:m:s'),
            'transaction_id' => \Str::orderedUuid(),
            'status' => 0,
            'to_sftp' => $toSftp,
        ];
    }
}
