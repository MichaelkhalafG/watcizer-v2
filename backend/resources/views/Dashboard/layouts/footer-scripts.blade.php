<a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

<!-- Vendor JS Files -->
{{-- <script src="{{ asset('DashAssets/vendor/apexcharts/apexcharts.min.js') }}"></script> --}}
<script src="{{ asset('DashAssets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
{{-- <script src="{{ asset('DashAssets/vendor/chart.js/chart.umd.js') }}"></script> --}}
{{-- <script src="{{ asset('DashAssets/vendor/echarts/echarts.min.js') }}"></script> --}}
<script src="{{ asset('DashAssets/vendor/quill/quill.min.js') }}"></script>
{{-- <script src="{{ asset('DashAssets/vendor/tinymce/tinymce.min.js') }}"></script> --}}
<script src="{{ asset('DashAssets/vendor/php-email-form/validate.js') }}"></script>
<script src="{{ asset('DashAssets/vendor/simple-datatables/simple-datatables.js') }}"></script>
<script src="{{ asset('DashAssets/vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('DashAssets/vendor/select2/js/select2.js') }}"></script>
<script src="{{ asset('DashAssets/vendor/lightbox2/js/lightbox2.min.js') }}"></script>

<!-- Template Main JS File -->
<script src="{{ asset('DashAssets/js/main.js') }}"></script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        // ============= التعديل هنا =============
        if(typeof myTable !== 'undefined' && myTable) {
            const dataTable = new simpleDatatables.DataTable(myTable , {
                searchable: true,       // Enable or disable the search bar
                fixedHeight: true,       // Make the table height fixed
                labels: {
                    placeholder: "Search...", // Customize search input placeholder
                    perPage: "", // Label for page entries dropdown
                    noRows: "No entries to display",      // Message for empty table
                    info: "Showing {start} to {end} of {rows} entries" // Info label
                }
            });
        }
        // =====================================
    });
</script>

@yield('script')