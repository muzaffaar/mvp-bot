<?php
namespace App\Support;
use Carbon\Carbon;
use Carbon\CarbonInterface;
final class TashkentDateTime {
 public const TIMEZONE = 'Asia/Tashkent';
 private const MONTHS=[1=>'Yanvar',2=>'Fevral',3=>'Mart',4=>'Aprel',5=>'May',6=>'Iyun',7=>'Iyul',8=>'Avgust',9=>'Sentabr',10=>'Oktyabr',11=>'Noyabr',12=>'Dekabr'];
 public static function normalize(mixed $value): ?Carbon { if($value===null||$value==='') return null; if($value instanceof CarbonInterface) return Carbon::instance($value)->setTimezone(self::TIMEZONE); $value=trim((string)$value); if(preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i',$value)) return Carbon::parse($value)->setTimezone(self::TIMEZONE); return Carbon::parse($value,self::TIMEZONE)->setTimezone(self::TIMEZONE); }
 public static function format(mixed $value,bool $includeYear=true): string { $date=self::normalize($value); if(!$date) return 'Belgilanmagan'; $month=self::MONTHS[(int)$date->month]; $year=$includeYear?' '.$date->year:''; return sprintf('%d-%s%s, %s',$date->day,$month,$year,$date->format('H:i')); }
 public static function short(mixed $value): string { $date=self::normalize($value); if(!$date) return '—'; return sprintf('%d-%s, %s',$date->day,self::MONTHS[(int)$date->month],$date->format('H:i')); }
}
