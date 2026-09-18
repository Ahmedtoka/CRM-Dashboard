<?php

namespace App\Bot\Flow;

interface TurnUnderstanding
{
    /**
     * @param  list<array{role:'customer'|'agent', text:string}>  $history  messages before the burst, oldest first
     * @param  list<string>  $burstTexts  the customer's latest messages, oldest first
     * @param  list<array{key:string, label:string, hints:list<string>}>  $catalog
     */
    public function understand(array $history, array $burstTexts, array $catalog): Understanding;
}
