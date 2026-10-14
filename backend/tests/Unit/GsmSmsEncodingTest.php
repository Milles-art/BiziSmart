<?php
use App\Support\GsmSmsEncoding;
it('accepts Swahili Latin text GSM accents and GSM extension characters without changing them',function(){
    foreach(['Duka lako: salio TSh 1200. Karibu tena!','Café Ångström àéùìò ÇØøÅåÄÖÑÜ§¿äöñü','ΔΦΓΛΩΠΨΣΘΞÆæßÉ','{}[]\\~|^€',"Line one\nLine two\r\f"] as $text)expect(GsmSmsEncoding::supports($text))->toBeTrue();
});
it('rejects Unicode unsupported ASCII controls and malformed UTF8 instead of guessing an encoding selector',function(){
    foreach(['Receipt 🙂','商品','Crème brûlée','Owner’s receipt','`',"\t","\x1b","\xC3\x28"] as $text)expect(GsmSmsEncoding::supports($text))->toBeFalse();
});
