<?php

return [
    'errors' => [
        'required'  => 'gerekli alan.',
        'email'     => 'geçerli bir E-Posta adresi olmalıdır.',
        'type'      => 'girdiğiniz veri {now-type}, ama sadece {must-type} tipi kabul edilebilir.',
        'max'       => 'girdiğiniz veri karakter {now-val}, ama en fazla {max-val} karakter olabilir.',
        'min'       => 'girdiğiniz veri karakter {now-val}, ama en az {min-val} karakter olabilir.',
        'same'      => 'değer {attribute-name} ile aynı değil.',
        'unique'    => 'zaten kullanımda.',
        'exists'    => 'böyle bir veri yok.',
        'in'        => 'listede yok. kabul edilenler: {allowed}.',
        'not-in'    => 'kullanilamaz.',
        'regex'     => 'istenen bicimde degil.',
        'url'       => 'gecerli bir adres olmalidir (http veya https).',
        'date'      => 'gecerli bir tarih degil.',
        'between'   => 'girdiginiz {now-val}, {min-val} ile {max-val} arasinda olmalidir.',
        'confirmed' => '{other} ile ayni degil.',
    ],

    'attributes' => [
        'username'    => 'Kullanıcı adı',
        'password'    => 'Şifre',
        're-password' => 'Tekrar Şifre',
        'email'       => 'E-Posta',
        'terms'       => 'Kullanıcı Sözleşmesi Politikası'
    ]
];
