{**
 * Template admin - Export comptable (module: export_comptable)
 * $rows est un tableau de groupes : $rows[i] = [ligne1, ligne2, ...]
 *}

<div class="panel">
    <h3><i class="icon-download"></i> {l s='Export comptable' mod='export_comptable'}</h3>

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
        <table class="table table-bordered">
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

            <tbody>
                {if $rows|@count == 0}
                    <tr>
                        <td colspan="{$headers[1]|@count}" class="text-center text-muted">
                            {l s='Aucune donnée pour les critères sélectionnés.' mod='export_comptable'}
                        </td>
                    </tr>
                {else}
                    {foreach from=$rows item=invoiceRows}
                        {foreach from=$invoiceRows item=line}
                            <tr>
                                {foreach from=$line item=cell}
                                    <td>{$cell|escape:'html'}</td>
                                {/foreach}
                            </tr>
                        {/foreach}
                    {/foreach}
                {/if}
            </tbody>
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
</style>