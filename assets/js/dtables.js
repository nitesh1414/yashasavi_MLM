/* DataTables — client-side sorting, instant search, per-column filters and
   pagination for the listing tables (class "table-dt") in the user, admin
   and superadmin panels. Loaded on every panel page via dash_footer.
   - Global search box (top right)
   - A filter input under EVERY column
   - Zebra striping via the row odd/even classes (styled in dash.css)
   Click a column name to sort. */
(function () {
    if (!window.jQuery || !jQuery.fn.DataTable) { return; }
    jQuery('table.table-dt').each(function () {
        var $table = jQuery(this);
        $table.DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            order: [],
            autoWidth: false,
            language: {
                search: 'Search:',
                lengthMenu: 'Show _MENU_ per page',
                info: 'Showing _START_\u2013_END_ of _TOTAL_ records',
                infoEmpty: 'No records found',
                infoFiltered: '(filtered from _MAX_ total)',
                paginate: { first: '\u00AB', previous: '\u2039', next: '\u203A', last: '\u00BB' },
                emptyTable: 'No records found',
                zeroRecords: 'No matching records found'
            },
            initComplete: function () {
                var api = this.api();
                /* per-column filter inputs in a generated footer */
                var $tfoot = $table.find('tfoot');
                if (!$tfoot.length) {
                    $tfoot = jQuery('<tfoot>').appendTo($table);
                    api.columns().every(function () { $tfoot.append('<td></td>'); });
                } else if (!$tfoot.find('input').length) {
                    var cells = $tfoot.find('td, th');
                    api.columns().every(function (idx) {
                        if (cells.eq(idx).find('input').length) { return; }
                        cells.eq(idx).empty();
                    });
                }
                api.columns().every(function () {
                    var col = this;
                    jQuery('<input type="text" class="dt-col-filter" placeholder="Filter" />')
                        .appendTo($tfoot.find('td, th').eq(col.index()).empty())
                        .on('keyup change', function () {
                            if (col.search() !== this.value) {
                                col.search(this.value).draw();
                            }
                        });
                });
            }
        });
    });
})();
