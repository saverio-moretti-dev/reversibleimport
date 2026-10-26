{**
 * @license MIT
 *}
<div class="panel">
  <h3><i class="icon-upload"></i> {l s='1. Carica il file' mod='reversibleimport'}</h3>
  <p>{l s='CSV con le colonne riferimento; prezzo (IVA esclusa); quantità. Separatore «;» o «,». Si può omettere prezzo o quantità.' mod='reversibleimport'}</p>
  <form method="post" action="{$action|escape:'html':'UTF-8'}" enctype="multipart/form-data" class="form-inline">
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <button type="submit" name="submitPreview" class="btn btn-default">{l s='Anteprima' mod='reversibleimport'}</button>
  </form>
</div>

{if $preview}
<div class="panel">
  <h3><i class="icon-eye"></i> {l s='2. Anteprima di' mod='reversibleimport'} {$preview.file_name|escape:'html':'UTF-8'}</h3>
  <p>
    <strong>{$preview.change_count|intval}</strong> {l s='campi da cambiare' mod='reversibleimport'} ·
    {$preview.unchanged|intval} {l s='righe già allineate' mod='reversibleimport'} ·
    {$preview.unknown|count} {l s='riferimenti non trovati o non univoci' mod='reversibleimport'} ·
    {$preview.errors|count} {l s='righe con errori' mod='reversibleimport'}
  </p>

  {if $preview.errors}
  <div class="alert alert-warning">
    <ul>{foreach $preview.errors as $error}<li>{l s='Riga' mod='reversibleimport'} {$error->line|intval}: {$error->message|escape:'html':'UTF-8'}</li>{/foreach}</ul>
  </div>
  {/if}

  {if $preview.unknown}
  <p class="text-muted">{l s='Non trovati:' mod='reversibleimport'} {foreach $preview.unknown as $ref name=u}{$ref|escape:'html':'UTF-8'}{if !$smarty.foreach.u.last}, {/if}{/foreach}</p>
  {/if}

  {if $preview.changes}
  <table class="table">
    <thead><tr><th>{l s='Riferimento' mod='reversibleimport'}</th><th>{l s='Campo' mod='reversibleimport'}</th><th>{l s='Prima' mod='reversibleimport'}</th><th>{l s='Dopo' mod='reversibleimport'}</th></tr></thead>
    <tbody>
    {foreach $preview.changes as $change}
      <tr>
        <td>{$change->reference|escape:'html':'UTF-8'}</td>
        <td>{if $change->field == 'price'}{l s='prezzo' mod='reversibleimport'}{else}{l s='quantità' mod='reversibleimport'}{/if}</td>
        <td>{$change->before|escape:'html':'UTF-8'}</td>
        <td><strong>{$change->after|escape:'html':'UTF-8'}</strong></td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  {if $preview.change_count > $preview.changes|count}<p class="text-muted">{l s='Mostrate le prime 200 modifiche.' mod='reversibleimport'}</p>{/if}
  {/if}

  {if $preview.plan_token}
  <form method="post" action="{$action|escape:'html':'UTF-8'}">
    <input type="hidden" name="plan_token" value="{$preview.plan_token|escape:'html':'UTF-8'}">
    <button type="submit" name="submitApply" class="btn btn-primary">{l s='Conferma e scrivi' mod='reversibleimport'}</button>
  </form>
  {else}
  <p>{l s='Niente da scrivere.' mod='reversibleimport'}</p>
  {/if}
</div>
{/if}

<div class="panel">
  <h3><i class="icon-undo"></i> {l s='3. Import fatti' mod='reversibleimport'}</h3>
  {if $batches}
  <table class="table">
    <thead><tr><th>#</th><th>{l s='File' mod='reversibleimport'}</th><th>{l s='Modifiche' mod='reversibleimport'}</th><th>{l s='Chi' mod='reversibleimport'}</th><th>{l s='Quando' mod='reversibleimport'}</th><th></th></tr></thead>
    <tbody>
    {foreach $batches as $batch}
      <tr>
        <td>{$batch.id_batch|intval}</td>
        <td>{$batch.file_name|escape:'html':'UTF-8'}</td>
        <td>{$batch.change_count|intval}</td>
        <td>{$batch.firstname|escape:'html':'UTF-8'} {$batch.lastname|escape:'html':'UTF-8'}</td>
        <td>{$batch.date_add|escape:'html':'UTF-8'}</td>
        <td>
          {if $batch.date_undone}
            {l s='riportato indietro il' mod='reversibleimport'} {$batch.date_undone|escape:'html':'UTF-8'}
          {else}
          <form method="post" action="{$action|escape:'html':'UTF-8'}">
            <input type="hidden" name="id_batch" value="{$batch.id_batch|intval}">
            <button type="submit" name="submitUndo" class="btn btn-default btn-sm">{l s='Torna indietro' mod='reversibleimport'}</button>
          </form>
          {/if}
        </td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  {else}
  <p>{l s='Ancora nessun import.' mod='reversibleimport'}</p>
  {/if}
</div>
