<?php
namespace lib\Health;

class ReportCommand
{
    public static function parse($arguments)
    {
        $options = [
            'date'=>null,
            'send'=>false,
            'use_ai'=>false,
            'force'=>false,
        ];
        $aiFlag = null;
        $seen = ['send'=>false, 'ai'=>false, 'no_ai'=>false, 'force'=>false];
        foreach($arguments as $argument){
            if($argument === '--send'){
                if($seen['send']) throw new \InvalidArgumentException('Duplicate --send option');
                $seen['send'] = true;
                $options['send'] = true;
            }
            elseif($argument === '--ai'){
                if($seen['ai']) throw new \InvalidArgumentException('Duplicate --ai option');
                if($aiFlag === false) throw new \InvalidArgumentException('Conflicting AI options');
                $seen['ai'] = true;
                $aiFlag = true;
            }elseif($argument === '--no-ai'){
                if($seen['no_ai']) throw new \InvalidArgumentException('Duplicate --no-ai option');
                if($aiFlag === true) throw new \InvalidArgumentException('Conflicting AI options');
                $seen['no_ai'] = true;
                $aiFlag = false;
            }
            elseif($argument === '--force'){
                if($seen['force']) throw new \InvalidArgumentException('Duplicate --force option');
                $seen['force'] = true;
                $options['force'] = true;
            }
            elseif(substr($argument, 0, 2) === '--') throw new \InvalidArgumentException('Unknown option: '.$argument);
            elseif($options['date'] !== null) throw new \InvalidArgumentException('Only one report date may be specified');
            else $options['date'] = $argument;
        }
        if($options['send'] && $aiFlag === false) throw new \InvalidArgumentException('--send requires AI analysis');
        $options['use_ai'] = $options['send'] || $aiFlag === true;
        return $options;
    }
}
