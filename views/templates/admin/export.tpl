{**
 * Template admin - Export comptable
 *}

<div class="panel">
    <h3><i class="icon-download"></i> {l s='Export comptable' mod='export_comptable'}</h3>

    <form method="get" class="form-inline" style="gap:8px; display:flex; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="controller" value="AdminExportComptable" />
        <input type="hidden" name="token" value="{$smarty.get.token|escape:'html'}" />

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

        <button type="submit" name="export_csv" value="1" class="btn btn-default">
            <i class="icon-file-text"></i> {l s='Exporter en CSV' mod='export_comptable'}
        </button>

        {if not $date_from && not $date_to}
            <span class="help-block" style="margin-left:8px">
                {l s='Affichage des 100 dernières factures par défaut.' mod='export_comptable'}
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
            {if $rows|@count == 0}
                <tbody>
                    <tr>
                        <td colspan="37" class="text-center text-muted">
                            {l s='Aucune donnée pour les critères sélectionnés.' mod='export_comptable'}
                        </td>
                    </tr>
                </tbody>
            {else}
                {foreach from=$rows item=lines}
                    <tbody>
                        {foreach from=$lines item=line}
                            <tr>
                                {foreach from=$line key=k item=v}
                                    <td>{$v|escape:'html'}</td>
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
</style>