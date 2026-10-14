<?php
namespace App\Support;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Inclusive Tanzania dates become one half-open UTC interval, shared by every report query. */
final class ReportPeriod {
    public readonly string $start;
    public readonly string $until;
    public function __construct(public readonly string $from, public readonly string $to) {
        $zone=new DateTimeZone('Africa/Dar_es_Salaam');
        $dates=[];
        foreach ([$from,$to] as $value) {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
            if (!$date || $date->format('Y-m-d')!==$value) throw new InvalidArgumentException('Enter valid report dates.');
            $dates[]=$date;
        }
        if ($dates[1]<$dates[0] || $dates[1]->getTimestamp()-$dates[0]->getTimestamp()>365*86400) throw new InvalidArgumentException('Choose an ordered date range of at most 366 days.');
        $utc=new DateTimeZone('UTC');
        $this->start=$dates[0]->setTimezone($utc)->format('Y-m-d H:i:s');
        $this->until=$dates[1]->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    }
}
