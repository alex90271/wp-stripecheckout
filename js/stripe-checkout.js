(function ($) {
    // Constants
    const MAX_PER_ITEM = window.stripe_checkout_vars?.max_quantity_per_item || 10;
    
    // State management
    let cart = {};
    let productCache = window.stripe_checkout_vars?.products || [];
    const shippingRate = window.stripe_checkout_vars?.shipping_rate_info;

    function initializeStore() {
        if (window.stripe_checkout_vars?.store_disabled) {
            return;
        }

        displayProducts();
        updateCartEfficiently();

        // Initialize event listeners
        initializeEventListeners();
    }

    function displayProducts() {
        const productList = $('#product-list');
        productList.empty();

        if (!Array.isArray(productCache) || productCache.length === 0) {
            productList.html('<p>No products available at the moment.</p>');
            return;
        }

        productList.addClass('product-grid');

        productCache.forEach(product => {
            if (!product || !product.id) {
                console.error('Invalid product data:', product);
                return;
            }

            const priceDisplay = product.price
                ? formatPrice(product.price, product.currency)
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
            const product = productCache.find(p => p.id === productId);
            if (!product) return;
            
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

    function addToCart(productId, button) {
        const product = productCache.find(p => p.id === productId);
        if (product) {
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
    }

    function initializeEventListeners() {
        // Add to cart button
        $(document).on('click', '.add-to-cart', function(e) {
            e.preventDefault();
            const productId = $(this).data('product-id');
            addToCart(productId, $(this));
        });

        // Quantity controls
        $(document).on('click', '.quantity-btn.increase', function() {
            const productId = $(this).data('product-id');
            updateQuantity(productId, 1);
        });

        $(document).on('click', '.quantity-btn.decrease', function() {
            const productId = $(this).data('product-id');
            updateQuantity(productId, -1);
        });

        // Remove item
        $(document).on('click', '.remove-item', function() {
            const productId = $(this).data('product-id');
            removeItem(productId);
        });

        // Checkout button
        $('#checkout-button').on('click', function() {
            const button = $(this);
            button.prop('disabled', true).addClass('store-button-disabled').text('Processing...');

            const cartArray = Object.values(cart).map(item => ({
                id: item.id,
                quantity: item.quantity
            }));

            // Submit form with cart data
            const form = $('<form>')
                .attr('method', 'POST')
                .attr('action', window.stripe_checkout_vars.checkout_url)
                .css('display', 'none');

            $('<input>')
                .attr('type', 'hidden')
                .attr('name', 'cart_data')
                .attr('value', JSON.stringify(cartArray))
                .appendTo(form);

            form.appendTo('body').submit();
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        try {
            if (document.readyState === 'complete') {
                initializeStore();
            } else {
                $(window).on('load', initializeStore);
            }
        } catch (error) {
            console.error('Critical initialization error:', error);
            $('#product-list').html('<div class="error-message">Failed to initialize store. Please refresh the page.</div>');
        }
    });

})(jQuery);