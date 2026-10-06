<?php

return [

    'admin' => [
        'access_denied' => 'API Tpay odmówiło dostępu. Sprawdź uprawnienia konta.',
        'unauthorized' => 'Autoryzacja API Tpay nie powiodła się. Sprawdź TPAY_CLIENT_ID i TPAY_CLIENT_SECRET.',

        'invalid_amount' => 'Tpay odrzucił kwotę płatności.',
        'invalid_currency' => 'Tpay odrzucił walutę płatności.',
        'invalid_payer' => 'Tpay odrzucił dane płatnika.',
        'invalid_pos_id' => 'Tpay odrzucił POS ID.',
        'invalid_transaction' => 'Tpay odrzucił transakcję.',

        'refund_record_not_found' => 'Nie znaleziono rekordu transakcji Tpay dla tej transakcji Lunar.',
        'refund_not_confirmed' => 'Zwroty można wystawiać tylko dla płatności Tpay w statusie correct (aktualny status: :status).',
        'refund_exceeds_balance' => 'Kwota zwrotu (:amount) przekracza dostępne saldo (:available).',
        'refund_not_cancellable' => 'Ten adapter nie obsługuje anulowania zwrotów Tpay (zwrot: :refund_id, status: :status).',
        'missing_buyer_email' => 'Nie można utworzyć transakcji Tpay: brak adresu e-mail w adresie rozliczeniowym lub koncie klienta.',

        'not_found' => 'Zasób Tpay nie został znaleziony.',
        'validation_error' => 'Błąd walidacji żądania Tpay: :message',
        'generic' => 'Błąd API Tpay: :message',
    ],

    'customer' => [
        'default_payer_name' => 'Klient',
        'invalid_amount' => 'Kwota płatności jest nieprawidłowa.',
        'invalid_currency' => 'Wybrana waluta nie jest obsługiwana.',
        'invalid_payer' => 'Sprawdź dane rozliczeniowe i spróbuj ponownie.',
        'validation_error' => 'Sprawdź dane płatności i spróbuj ponownie.',
        'generic' => 'Płatność nie powiodła się. Spróbuj ponownie lub wybierz inną metodę płatności.',
    ],

    'blik' => [
        'wrong_code' => 'Nieprawidłowy lub wygasły kod BLIK. Wygeneruj nowy kod w aplikacji bankowej i spróbuj ponownie.',
        'rejected_by_payer' => 'Płatność BLIK została odrzucona w aplikacji bankowej. Wygeneruj nowy kod, aby spróbować ponownie.',
        'insufficient_funds' => 'Bank odrzucił płatność BLIK z powodu braku środków.',
        'timeout' => 'Płatność BLIK nie została potwierdzona na czas. Wygeneruj nowy kod i spróbuj ponownie.',
        'limit_exceeded' => 'Przekroczono limit płatności BLIK ustawiony w banku.',
        'generic' => 'Płatność BLIK nie powiodła się. Spróbuj ponownie lub wybierz inną metodę płatności.',
    ],

];
