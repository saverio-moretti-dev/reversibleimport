# reversibleimport

Modulo PrestaShop 8.1 per aggiornare prezzi e quantità da un CSV, con anteprima e ritorno indietro.

È una versione ridotta, scritta da zero e pubblica, dell'import che uso al lavoro (quello resta dell'azienda e non è qui).

## Il problema

L'import CSV nativo di PrestaShop non si annulla: se il file era sbagliato, i valori di prima sono persi. Se va in errore a metà, una parte del catalogo resta scritta e una no.

## Cosa fa

1. **Anteprima.** Legge il file e mostra, campo per campo, il valore di oggi e quello nuovo. Elenca tutte le righe sbagliate con il numero di riga, i riferimenti non trovati e le righe già allineate. Non scrive niente.
2. **Conferma.** Scrive esattamente ciò che l'anteprima ha mostrato, in una sola transazione, insieme alla fotografia dei valori di prima. Se una scrittura fallisce non resta niente a metà. Se fra anteprima e conferma un valore è cambiato (per esempio è arrivato un ordine), l'import si ferma e chiede di rifare l'anteprima.
3. **Ritorno indietro.** Dall'elenco degli import, un clic riporta i campi al valore di prima. Un campo cambiato da qualcun altro dopo l'import (una vendita, una correzione a mano) resta com'è e viene segnalato: riportarlo indietro cancellerebbe un lavoro che l'import non ha fatto.

Il file: colonne `riferimento; prezzo; quantità` (accetta anche `reference`, `sku`, `price`, `qta`…), separatore `;` o `,`, prezzi come `12,50`, `1.234,56` o `1,234.56`. Prezzo IVA esclusa, come nel back office.

## Limiti

- Negozio singolo; prodotti senza combinazioni (la loro quantità è la somma delle varianti, va gestita a parte). Un riferimento usato da più prodotti viene saltato.
- Solo prezzo e quantità.
- Scrive con SQL diretto, non con `Product::save()`, per non riscrivere l'intero prodotto e non lanciare hook dentro una transazione che può ancora essere annullata. L'hook `actionUpdateQuantity` parte dopo il commit, una volta per prodotto. Altri moduli che ascoltano il salvataggio del prodotto non vengono avvisati del cambio di prezzo.
- **Stato:** la logica (lettura del file, anteprima, scrittura, ritorno indietro) è coperta da test automatici. Il modulo non è ancora stato installato su un PrestaShop di prova.

## Come è fatto

```
src/CsvReader.php                    lettura e controllo del file
src/Planner.php                      anteprima: confronto file / catalogo
src/Importer.php                     scrittura in transazione e ritorno indietro
src/CatalogRepository.php            ciò che la logica chiede al catalogo
src/PrestaShopCatalogRepository.php  il catalogo PrestaShop (Db, pSQL, cast a int)
controllers/admin/                   pagina nel back office (Catalogo > Import reversibile)
tests/                               test con un catalogo in memoria che simula transazioni e guasti
```

La logica non dipende da PrestaShop: parla con un'interfaccia. Così si prova senza un negozio, compreso il caso «guasto alla terza scrittura».

Codice scritto in gran parte con Claude Code; logica, casi da provare e verifica sono miei.

## Test

```
composer install
vendor/bin/phpunit
```

Girano anche a ogni push (GitHub Actions, PHP 8.1 e 8.3).

## Installazione

Copiare la cartella in `modules/reversibleimport` e installare il modulo dal back office. La disinstallazione cancella la storia degli import.

Licenza MIT.
