{**
 * Template admin - Export comptable (module: export_comptable)
 * $rows est un tableau de groupes : $rows[i] = [ligne1, ligne2, ...]
 *}

<div class="panel">
    <h3><i class="icon-download"></i> {l s='Export comptable' mod='export_comptable'}</h3>

    <div class="alert alert-info" style="margin-bottom:16px;">
        <i class="icon-info-circle"></i>
        {l s='Export automatique chaque jour par tâche cron :' mod='export_comptable'}<br>
        <code>0 2 * * * php /var/www/html/pts/modules/export_comptable/cron_export_comptable.php</code><br>
        {l s='Le fichier généré se trouve dans le dossier "exports" sous le nom :' mod='export_comptable'}<br>
        <code>export_comptable_YYYY-MM-DD.xlsx</code>
    </div>

    <form method="get" class="form-inline" style="gap:8px; display:flex; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="controller" value="AdminExportComptable" />
        <input type="hidden" name="token" value="{$token|escape:'html'}" />

        <div class="form-group">
            <label for="date_from">{l s='Date début' mod='export_comptable'}</label>
            <input type="date" class="form-control" id="date_from" name="date_from" value="{$date_from|escape:'html'}">
        </div>

        <div class="form-group">
            <label for="date_to">{l s='Date fin' mod='export_comptable'}</label>
            <input type="date" class="form-control" id="date_to" name="date_to" value="{$date_to|escape:'html'}">
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="icon-search"></i> {l s='Filtrer' mod='export_comptable'}
        </button>

        <button type="submit" name="export_xlsx" value="1" class="btn btn-default">
            <i class="icon-file-excel-o"></i> {l s='Exporter en XLSX' mod='export_comptable'}
        </button>

        {if not $date_from && not $date_to}
            <span class="help-block" style="margin-left:8px">
                {l s='Affichage des 100 dernières factures et avoirs par défaut.' mod='export_comptable'}
            </span>
        {/if}
    </form>

    <div class="table-responsive" style="margin-top:15px;">
        <table class="table">
            <thead class="thead-dark sticky-header">
                <tr>
                    {foreach from=$headers[0] item=th}
                        <th>{$th|escape:'html'}</th>
                    {/foreach}
                </tr>
                <tr>
                    {foreach from=$headers[1] item=th2}
                        <th>{$th2|escape:'html'}</th>
                    {/foreach}
                </tr>
            </thead>

            {if $rows|@count == 0}
                <tbody>
                    <tr>
                        <td colspan="{$headers[1]|@count}" class="text-center text-muted">
                            {l s='Aucune donnée pour les critères sélectionnés.' mod='export_comptable'}
                        </td>
                    </tr>
                </tbody>
            {else}
                {assign var=alt value=0}
                {foreach from=$rows item=invoiceRows name=group}
                    {assign var=debit value=0}
                    {assign var=credit value=0}
                    {foreach from=$invoiceRows item=line}
                        {if $line.CODC == 'D'}
                            {assign var=debit value=$debit + (floatval(str_replace(",", ".", $line.MONT)))}
                        {elseif $line.CODC == 'C'}
                            {assign var=credit value=$credit + (floatval(str_replace(",", ".", $line.MONT)))}
                        {/if}
                    {/foreach}
                    {assign var=equilibre value=($debit|string_format:"%.2f") == ($credit|string_format:"%.2f")}
                    <tbody style="background:{if $smarty.foreach.group.iteration % 2 == 1}#fff{else}#f0f0f0{/if}">
                        <tr>
                            <td colspan="{$headers[1]|@count}"
                                style="font-weight:bold; color:#fff; border-radius:4px; background:{if $equilibre}#4caf50!important{else}#ff9800!important{/if};">
                                {if $equilibre}
                                    <i class="icon-check"></i> {l s='Équilibré' mod='export_comptable'}
                                {else}
                                    <i class="icon-warning"></i> {l s='Non équilibré' mod='export_comptable'}
                                {/if}
                                — Débit : {$debit|string_format:"%.2f"} — Crédit : {$credit|string_format:"%.2f"}
                            </td>
                        </tr>
                        {foreach from=$invoiceRows item=line name=lines}
                            <tr>
                                <td style="font-weight:bold;color:#888;">
                                    {if $smarty.foreach.lines.index == 0}TTC
                                    {elseif $smarty.foreach.lines.index == 1}HT
                                    {elseif $smarty.foreach.lines.index == 2}Frais port
                                    {elseif $smarty.foreach.lines.index == 3}TVA
                                    {elseif $smarty.foreach.lines.index == 4}Consigne
                                    {/if}
                                </td>
                                {foreach from=$line item=cell}
                                    <td>{$cell|escape:'html'}</td>
                                {/foreach}
                            </tr>
                        {/foreach}
                    </tbody>
                {/foreach}
            {/if}
        </table>
    </div>
</div>

<style>
    .sticky-header th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #fff;
        color: #000;
    }

    thead tr:nth-child(2) th {
        position: sticky;
        top: 38px;
        z-index: 1;
        background: #fff;
        color: #333;
        font-weight: normal;
    }

    tbody {
        border-bottom: solid 3px #333;
    }

    /* Correction du style Bootstrap pour permettre la couleur de fond du tbody */
    .bootstrap .table tbody>tr>td {
        padding: 3px 7px;
        font-size: 12px;
        color: #666;
        word-wrap: nowrap;
        vertical-align: middle;
        background-color: inherit !important;
        border-top: none;
        border-bottom: solid 1px #eaedef;
    }

    .equilibre-row.equilibre-ok td {
        background: #4caf50 !important;
        color: #fff !important;
    }

    .equilibre-row.equilibre-ko td {
        background: #ff9800 !important;
        color: #fff !important;
    }
</style>