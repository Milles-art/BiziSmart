<?php
namespace App\Support;

/** GSM default alphabet and extension table, ETSI TS 123 038 sections 6.2.1/6.2.1.1. */
final class GsmSmsEncoding {
    private const BASIC='@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    private const EXTENDED='^{}\\[~]|€';
    public static function supports(string $text):bool {
        if(!mb_check_encoding($text,'UTF-8'))return false;
        foreach(mb_str_split($text) as $character)if(!str_contains(self::BASIC,$character)&&!str_contains(self::EXTENDED,$character)&&!in_array($character,["\n","\r","\f"],true))return false;
        return true;
    }
}
