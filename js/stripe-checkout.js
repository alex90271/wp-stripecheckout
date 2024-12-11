(function ($) {
    // Constants
    const AJAX_TIMEOUT = 15000; // 15 seconds
    const MAX_RETRIES = 2;
    
    // State management
    let cart = {};
    let shippingRate = null;
    let productCache = {};
    let isLoading = false;
    let retryCount = 0;

    function initializeStore() {
        // Ensure required variables exist
        if (typeof stripe_checkout_vars === 'undefined') {
            console.error('Stripe checkout variables not loaded');
            showError('Store configuration error. Please refresh the page.');
            return;
        }

        if (stripe_checkout_vars.store_disabled == '1') {
            return;
        }

        fetchProducts();
        initShippingRate();
        updateCartEfficiently();
    }

    function showError(message) {
        const productList = $('#product-list');
        productList.html(`<div class="store-error-message">${message}</div>`);
    }

    function showLoading() {
        if (!isLoading) {
            isLoading = true;
            const productList = $('#product-list');
            productList.html('<div class="store-loading-indicator">Loading products...</div>');
        }
    }

    function hideLoading() {
        isLoading = false;
    }

    function fetchProducts() {
        if (isLoading) return;

        showLoading();

        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'fetch_stripe_products',
                _ajax_nonce: stripe_checkout_vars.fetch_products_nonce
            },
            success: function (response) {
                if (response.success && Array.isArray(response.data)) {
                    retryCount = 0; // Reset retry count on success
                    response.data.forEach(product => {
                        productCache[product.id] = product;
                    });
                    displayProducts(response.data);
                } else {
                    handleFetchError('Invalid product data received');
                }
            },
            error: function (xhr, status, error) {
                handleFetchError(`Failed to load products: ${error}`);
            },
            complete: function() {
                hideLoading();
            }
        });
    }

    function handleFetchError(error) {
        console.error('Product fetch error:', error);
        
        if (retryCount < MAX_RETRIES) {
            retryCount++;
            console.log(`Retrying product fetch (attempt ${retryCount})`);
            setTimeout(fetchProducts, 2000 * retryCount); // Exponential backoff
        } else {
            showError('Unable to load products. Please refresh the page or try again later.');
        }
    }

    function displayProducts(products) {
        const productList = $('#product-list');
        productList.empty();

        if (!Array.isArray(products) || products.length === 0) {
            productList.html('<p>No products available at the moment.</p>');
            return;
        }

        productList.addClass('product-grid');

        products.forEach(product => {
            if (!product || !product.id) {
                console.error('Invalid product data:', product);
                return;
            }

            const priceDisplay = product.price
                ? `${formatPrice(product.price, product.currency)}`
                : 'Price not available';

            const imageUrl = product.image || 'https://placehold.co/600x400/000000/FFFFFF.png';
            const quantity = cart[product.id] ? cart[product.id].quantity : 0;
            const buttonText = quantity > 0 ? `Add to Cart (${quantity})` : 'Add to Cart';

            productList.append(`
                <div class="product-item">
                    <img src="${imageUrl}" alt="${product.name}" class="product-image">
                    <h4>${product.name}</h4>
                    <p class="product-description">${product.description || ''}</p>
                    <p class="product-price">${priceDisplay}</p>
                    ${product.price ? `<button class="store-button add-to-cart" data-product-id="${product.id}">${buttonText}</button>` : ''}
                </div>
            `);
        });
    }

    function formatPrice(amount, currency) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency || 'USD'
        }).format(amount / 100);
    }

    function updateCartEfficiently() {
        const cartEl = $('#cart');
        let cartHTML = '';
        let subtotal = 0;

        Object.keys(cart).forEach(productId => {
            const product = productCache[productId];
            if (!product) return; // Skip if product not found in cache
            
            const quantity = cart[productId].quantity;
            subtotal += product.price * quantity;

            cartHTML += `
                <div class="cart-item">
                    <div class="cart-item-details">
                        <span class="cart-item-name"><strong>${quantity}x</strong> ${product.name} | <i>($${(product.price/100).toFixed(2)} each)</i></span>
                    </div>
                    <div class="quantity-controls">
                        <button class="store-button quantity-btn decrease" data-product-id="${productId}">-</button>
                        <span class="quantity-display">${quantity}</span>
                        <button class="store-button quantity-btn increase" data-product-id="${productId}">+</button>
                    </div>
                    <button class="store-button remove-item" data-product-id="${productId}" title="Remove item">×</button>
                </div>
            `;
        });

        cartHTML += `<div class="cart-subtotal"><strong>Subtotal:</strong> ${formatPrice(subtotal, 'USD')}</div>`;

        if (shippingRate) {
            cartHTML += `
                <div class="cart-shipping">
                    <strong>Shipping:</strong>
                    ${formatPrice(shippingRate.amount, shippingRate.currency)}
                </div>
                <div class="cart-total"><strong>Total:</strong> ${formatPrice(subtotal + shippingRate.amount, 'USD')}</div>
            `;
        } else {
            cartHTML += '<div class="cart-shipping"><strong>Shipping:</strong> Not calculated</div>';
        }

        cartEl.html(cartHTML);

        const checkoutButton = $('#checkout-button');
        if (Object.keys(cart).length > 0) {
            checkoutButton.show();
        } else {
            checkoutButton.hide();
        }
    }

    function initShippingRate() {
        if (stripe_checkout_vars.shipping_rate_info) {
            shippingRate = stripe_checkout_vars.shipping_rate_info;
        }
    }

    const MAX_PER_ITEM = stripe_checkout_vars.max_quantity_per_item;

    function updateQuantity(productId, delta) {
        if (!cart[productId]) return;

        const newQuantity = cart[productId].quantity + delta;

        if (newQuantity > MAX_PER_ITEM) {
            alert(`Maximum quantity of ${MAX_PER_ITEM} reached for this item`);
            return;
        }

        if (newQuantity < 1) return;

        cart[productId].quantity = newQuantity;
        $(`.add-to-cart[data-product-id="${productId}"]`).text(`Add to Cart (${newQuantity})`);
        updateCartEfficiently();
    }

    function removeItem(productId) {
        if (cart[productId]) {
            delete cart[productId];
            $(`.add-to-cart[data-product-id="${productId}"]`).text('Add to Cart');
            updateCartEfficiently();
        }
    }

    const debouncedAddToCart = debounce(function (productId, button) {
        if (productCache[productId]) {
            if (cart[productId]) {
                if (cart[productId].quantity >= MAX_PER_ITEM) {
                    alert(`Maximum quantity of ${MAX_PER_ITEM} reached for this item`);
                    return;
                }
                cart[productId].quantity += 1;
            } else {
                cart[productId] = {
                    id: productId,
                    quantity: 1
                };
            }

            button.text(`Add to Cart (${cart[productId].quantity})`);
            updateCartEfficiently();
        }
    }, 50);

    function getTotalCartQuantity() {
        return Object.values(cart).reduce((total, item) => total + item.quantity, 0);
    }

    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Event Handlers
    $(document).on('click', '.add-to-cart', function (e) {
        e.preventDefault();
        const productId = $(this).data('product-id');

        if (cart[productId] && cart[productId].quantity >= MAX_PER_ITEM) {
            alert(`Maximum quantity of ${MAX_PER_ITEM} reached for this item`);
            return;
        }

        debouncedAddToCart(productId, $(this));
    });

    $(document).on('click', '.quantity-btn.increase', function () {
        const productId = $(this).data('product-id');
        updateQuantity(productId, 1);
    });

    $(document).on('click', '.quantity-btn.decrease', function () {
        const productId = $(this).data('product-id');
        updateQuantity(productId, -1);
    });

    $(document).on('click', '.remove-item', function () {
        const productId = $(this).data('product-id');
        removeItem(productId);
    });

    $('#checkout-button').on('click', function () {
        const button = $(this);
        button.prop('disabled', true).addClass('store-button-disabled').text('Processing...');

        const cartArray = Object.values(cart).map(item => ({
            id: item.id,
            quantity: item.quantity
        }));

        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'create_checkout_session',
                _ajax_nonce: stripe_checkout_vars.checkout_nonce,
                cart: JSON.stringify(cartArray)
            },
            success: function (response) {
                if (response.success && response.data && response.data.url) {
                    window.location.href = response.data.url;
                } else {
                    console.error('Invalid checkout response:', response);
                    handleCheckoutError(button);
                }
            },
            error: function (xhr, status, error) {
                console.error('Checkout error:', error);
                handleCheckoutError(button);
            }
        });
    });

    function handleCheckoutError(button) {
        button.prop('disabled', false)
             .removeClass('store-button-disabled')
             .text('Checkout');
        alert('There was an error processing your checkout. Please try again.');
    }

    // Initialize when document is ready and stripe_checkout_vars is available
    $(document).ready(function() {
        if (document.readyState === 'complete') {
            initializeStore();
        } else {
            // Wait for everything to load if document not complete
            $(window).on('load', initializeStore);
        }
    });
})(jQuery);