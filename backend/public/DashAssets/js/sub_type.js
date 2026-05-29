$(document).ready(function () {

    /// For open modal ///
    $(document).on('click' , '#openAddSubType' , function (e) {
        var url = $('#urlAddSubType').val();

        $.ajax({
            url: url,
            type: "get",
            dataType: "html",
            cache: false,
            data: {},
            success: function (data) {
                $('#Add_body').html(data);
                $('#Add').modal("show");
            },

        });
    });

    /// For request form ///
    var clickCount = 0;
    $(document).on('submit', '#addSubTypeForm', function (e) {
        e.preventDefault();

        clickCount++;
        if (clickCount === 5) {
            $("#add_btn").attr("disabled",true);
            alert('error to many click');
            location.reload();
        }

        var form = $(this);
        var url = form.attr('action');

        $.ajax({
            url: url,
            type: "post",
            data: form.serialize(),
            success: function (response) {
                $('#Add').modal("hide");
            },
            error: function (xhr) {
                if (xhr.responseJSON && xhr.responseJSON.errors) {
                    $.each(xhr.responseJSON.errors, function (key, messages) {
                        var inputField = $('[data-name="' + key + '"]');
                        inputField.next('.text-danger').remove();
                        inputField.after('<div class="text-danger">' + messages[0] + '</div>');
                    });
                } else {
                    alert("An unexpected error occurred.");
                }
            },

        });
    });

    /// For reload page and add new item in page ///
    $(document).on('submit', '#addSubTypeForm', function (e) {
        e.preventDefault();

        $.ajax({
            url: "http://127.0.0.1:4000/ar/admin/sub_type/reload",
            type: "get",
            success: function (data) {
                $('#DataAjax').html(data).show();
            },
            error: function (xhr) {
                console.error("An error occurred:", xhr);
            }
        });
    });

});

document.addEventListener("DOMContentLoaded", function() {
    const dataTable = new simpleDatatables.DataTable(myTable , {
        searchable: true,       // Enable or disable the search bar
        fixedHeight: true,       // Make the table height fixed
        labels: {
            placeholder: "Search...", // Customize search input placeholder
            perPage: " ", // Label for page entries dropdown
            noRows: "No entries to display",      // Message for empty table
            info: "Showing {start} to {end} of {rows} entries" // Info label
        }
    });
});
