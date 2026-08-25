<?php
namespace lib\Health;

class SnapshotCommand
{
    public static function parse($arguments, $defaultBackfill, $maxBackfill)
    {
        $options = ['force'=>false, 'time'=>null, 'backfill'=>intval($defaultBackfill)];
        $backfillSeen = false;
        foreach($arguments as $argument){
            if($argument === '--force'){
                if($options['force']) throw new \InvalidArgumentException('Duplicate --force option');
                $options['force'] = true;
            }elseif(strpos($argument, '--backfill=') === 0){
                if($backfillSeen) throw new \InvalidArgumentException('Duplicate --backfill option');
                $value = substr($argument, 11);
                if(!preg_match('/^[1-9][0-9]*$/D', $value) || intval($value) > intval($maxBackfill)){
                    throw new \InvalidArgumentException('Invalid --backfill value');
                }
                $options['backfill'] = intval($value);
                $backfillSeen = true;
            }elseif(substr($argument, 0, 2) === '--'){
                throw new \InvalidArgumentException('Unknown option: '.$argument);
            }elseif($options['time'] !== null){
                throw new \InvalidArgumentException('Only one snapshot time may be specified');
            }else{
                $options['time'] = $argument;
            }
        }
        return $options;
    }
}
