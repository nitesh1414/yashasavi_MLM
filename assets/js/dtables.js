/* DataTables: client-side sorting, instant search and pagination for the
   big listing tables (All Distributors, My Team). Click a column name to
   sort. Loaded only on pages that include the vendored DataTables libs. */
(function () {
    if (!window.jQuery || !jQuery.fn.DataTable) { return; }
    jQuery('table.table-dt').each(function () {
        jQuery(this).DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            order: [],
            language: {
                search: 'Search:',
                lengthMenu: 'Show _MENU_ per page',
                info: 'Showing _START_\u2013_END_ of _TOTAL_ members',
                infoEmpty: 'No members found',
                infoFiltered: '(filtered from _MAX_ total)',
                paginate: { first: '\u00AB', previous: '\u2039', next: '\u203A', last: '\u00BB' },
                emptyTable: 'No members found',
                zeroRecords: 'No matching members found'
            }
        });
    });
})();
