<script>
function switchTab(tabName) {
    window.location.href = '?tab=' + tabName;
}

// Category Management Functions
function addNewCategory() {
    const categoryInput = document.getElementById('categoryInput');
    if (categoryInput.value.trim() === '') {
        alert('Please enter a category name');
        return;
    }
    // The category will be processed when the form is submitted
}

function useExistingCategory() {
    const categorySelect = document.getElementById('categorySelect');
    const categoryInput = document.getElementById('categoryInput');
    if (categorySelect.value) {
        categoryInput.value = categorySelect.options[categorySelect.selectedIndex].text;
    }
}

function toggleCategories() {
    const categoriesSection = document.getElementById('categoriesSection');
    categoriesSection.style.display = categoriesSection.style.display === 'none' ? 'block' : 'none';
}

function createCategory() {
    const newCategoryName = document.getElementById('newCategoryName');
    const categoryName = newCategoryName.value.trim();
    
    if (categoryName === '') {
        alert('Please enter a category name');
        return;
    }
    
    // Submit the form via AJAX or page reload
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'category_name';
    input.value = categoryName;
    
    const submitInput = document.createElement('input');
    submitInput.type = 'hidden';
    submitInput.name = 'add_category';
    submitInput.value = '1';
    
    form.appendChild(input);
    form.appendChild(submitInput);
    document.body.appendChild(form);
    form.submit();
}

function deleteCategory(categoryId, categoryName) {
    if (confirm(`Are you sure you want to delete the category "${categoryName}"?`)) {
        window.location.href = `?tab=products&delete_category=${categoryId}`;
    }
}

// Product Search Functionality
document.getElementById('productSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#productTableBody tr');
    
    rows.forEach(row => {
        const productName = row.cells[0].textContent.toLowerCase();
        const categoryName = row.cells[1].textContent.toLowerCase();
        const description = row.cells[4].textContent.toLowerCase();
        
        if (productName.includes(searchTerm) || categoryName.includes(searchTerm) || description.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

// Add these functions to the existing JavaScript in footer.php

// Form Display Functions
let isFormVisible = false;

function showAddProductForm() {
    const form = document.getElementById('addProductForm');
    const button = document.getElementById('addProductBtn');
    
    if (!isFormVisible) {
        form.style.display = 'block';
        button.textContent = '✕ Cancel';
        button.classList.remove('btn-success');
        button.classList.add('btn-danger');
        isFormVisible = true;
    } else {
        hideAddProductForm();
    }
}

function hideAddProductForm() {
    const form = document.getElementById('addProductForm');
    const button = document.getElementById('addProductBtn');
    
    form.style.display = 'none';
    button.textContent = '➕ Add Product';
    button.classList.remove('btn-danger');
    button.classList.add('btn-success');
    isFormVisible = false;
    if (document.getElementById('productForm')) {
        document.getElementById('productForm').reset();
    }
    hideCategorySuggestions();
}

// Category Functions
function showCategorySuggestions() {
    const suggestions = document.getElementById('categorySuggestions');
    if (suggestions) suggestions.style.display = 'block';
}

function hideCategorySuggestions() {
    const suggestions = document.getElementById('categorySuggestions');
    if (suggestions) suggestions.style.display = 'none';
}

function filterCategories() {
    const input = document.getElementById('categoryInput');
    const suggestions = document.getElementById('categorySuggestions');
    if (!input || !suggestions) return;
    
    const filter = input.value.toLowerCase();
    const items = suggestions.getElementsByClassName('category-suggestion');
    
    for (let i = 0; i < items.length; i++) {
        const text = items[i].textContent || items[i].innerText;
        items[i].style.display = text.toLowerCase().includes(filter) ? '' : 'none';
    }
}

function selectCategory(categoryName) {
    const input = document.getElementById('categoryInput');
    if (input) {
        input.value = categoryName;
        hideCategorySuggestions();
    }
}   


document.getElementById('categoryInput').addEventListener('input', function(e) {
    const existingCategories = document.getElementById('existingCategories');
    if (e.target.value.length > 0) {
        existingCategories.style.display = 'block';
    } else {
        existingCategories.style.display = 'none';
    }
});

function showUpdateForm(productId) {
  
    document.querySelectorAll('.update-form').forEach(form => {
        form.style.display = 'none';
    });
  
    document.getElementById('form-' + productId).style.display = 'table-row';
}

function hideUpdateForm(productId) {
    document.getElementById('form-' + productId).style.display = 'none';
}

// Toast functions
function showLogoutToast() {
    const toastContainer = document.getElementById('toastContainer');
    
    const toast = document.createElement('div');
    toast.className = 'toast warning';
    toast.innerHTML = `
        <div class="toast-content">
            <span class="toast-icon">⚠️</span>
            <span class="toast-message">Are you sure you want to logout?</span>
        </div>
        <div class="toast-actions">
            <button class="toast-btn cancel" onclick="hideToast(this)">Cancel</button>
            <button class="toast-btn confirm" onclick="confirmLogout()">Yes, Logout</button>
        </div>
    `;
    
    toastContainer.appendChild(toast);
    
    setTimeout(() => {
        if (toast.parentNode) {
            hideToast(toast);
        }
    }, 10000);
}

function hideToast(element) {
    const toast = element.closest('.toast') || element;
    toast.classList.add('hide');
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 300);
}

function confirmLogout() {
    const toastContainer = document.getElementById('toastContainer');
    
    const successToast = document.createElement('div');
    successToast.className = 'toast success';
    successToast.innerHTML = `
        <div class="toast-content">
            <span class="toast-icon">✅</span>
            <span class="toast-message">Logging out...</span>
        </div>
    `;
    
    toastContainer.appendChild(successToast);
    
    setTimeout(() => {
        window.location.href = 'logout.php';
    }, 1000);
    
    const toasts = toastContainer.querySelectorAll('.toast');
    toasts.forEach(t => {
        if (t !== successToast) {
            hideToast(t);
        }
    });
}

document.addEventListener('click', function(event) {
    const toastContainer = document.getElementById('toastContainer');
    const toasts = toastContainer.querySelectorAll('.toast');
    
    toasts.forEach(toast => {
        if (!toast.contains(event.target) && !event.target.matches('.btn')) {
            hideToast(toast);
        }
    });
});
</script>