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
        'not-in'    => 'kullanılamaz. yasaklı olanlar: {blocked}.',
        'regex'     => 'istenen biçimde değil.',
        'url'       => 'geçerli bir adres olmalıdır (http veya https).',
        'date'      => 'geçerli bir tarih değil.',
        'between'   => 'girdiğiniz {now-val}, {min-val} ile {max-val} arasında olmalıdır.',
        'confirmed' => '{other} ile aynı değil.',
    ],

    'attributes' => [
        'username'    => 'Kullanıcı adı',
        'password'    => 'Şifre',
        're-password' => 'Tekrar Şifre',
        'email'       => 'E-Posta',
        'terms'       => 'Kullanıcı Sözleşmesi Politikası'
    ]
];
