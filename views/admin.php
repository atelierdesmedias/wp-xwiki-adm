<div class="wrap">
    <h2>XWiki ADM options</h2>

    <form method="POST">
        <input type="hidden" name="option_page" value="wp-xwiki-adm">

        <table class="form-table">
            <tbody>
                <tr>
                    <th>
                        <label for="endpoint">XWiki address</label>
                    </th>
                    <td>
                        <input id="endpoint" class="regular-text" type="text" name="xwiki_adm_endpoint"
                               value="<?php $o('xwiki_adm_endpoint'); ?>">
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <input class="button button-primary" type="submit" value="Enregistrer les modifications">
        </p>
    </form>
</div>