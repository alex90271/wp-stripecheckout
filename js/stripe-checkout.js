(function ($) {
    if (stripe_checkout_vars.store_disabled == '1') {
        return;
    }

    let cart = {};  // Will now store just {id, quantity}
    let shippingRate = null;
    let productCache = {};  // Keep cache for display purposes

    function fetchProducts() {
        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fetch_stripe_products',
                _ajax_nonce: stripe_checkout_vars.fetch_products_nonce

            },
            success: function (response) {
                if (response.success) {
                    response.data.forEach(product => {
                        productCache[product.id] = product;
                    });
                    displayProducts(response.data);
                } else {
                    console.error('Error fetching products:', response.data);
                }
            }
        });
    }

    function displayProducts(products) {
        const productList = $('#product-list');
        productList.empty();

        if (products.length === 0) {
            productList.append('<p>No products available at the moment.</p>');
            return;
        }

        productList.addClass('product-grid');

        products.forEach(product => {
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
                    ${product.price ? `<button class="add-to-cart btn btn-white" data-product-id="${product.id}">${buttonText}</button>` : ''}
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
            const quantity = cart[productId].quantity;
            subtotal += product.price * quantity;
            
            cartHTML += `
                <div class="cart-item">
                    <span class="cart-item-quantity"><strong style="padding-right: 5px">${quantity}x</strong> ${product.name}</span>
                    <button class="remove-from-cart" data-product-id="${productId}">X</button>
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

    const debouncedAddToCart = debounce(function(productId, button) {
        if (productCache[productId]) {
            if (cart[productId]) {
                // Check if adding one more would exceed the per-item limit
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

    // Add a helper function to check total cart quantity
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

    $(document).on('click', '.add-to-cart', function(e) {
        e.preventDefault();
        const productId = $(this).data('product-id');
        
        // Check only the specific item's quantity
        if (cart[productId] && cart[productId].quantity >= MAX_PER_ITEM) {
            alert(`Maximum quantity of ${MAX_PER_ITEM} reached for this item`);
            return;
        }
        
        debouncedAddToCart(productId, $(this));
    });

    $(document).on('click', '.remove-from-cart', function() {
        const productId = $(this).data('product-id');
        if (cart[productId]) {
            if (cart[productId].quantity > 1) {
                cart[productId].quantity -= 1;
                $(`.add-to-cart[data-product-id="${productId}"]`).text(`Add to Cart (${cart[productId].quantity})`);
            } else {
                delete cart[productId];
                $(`.add-to-cart[data-product-id="${productId}"]`).text('Add to Cart');
            }
            updateCartEfficiently();
        }
    });

    $('#checkout-button').on('click', function() {
        
        const button = $(this);
        button.prop('disabled', true).text('Processing...');
        
        // Convert cart object to array and only send necessary data
        const cartArray = Object.values(cart).map(item => ({
            id: item.id,
            quantity: item.quantity
        }));
        
        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'create_checkout_session',
                _ajax_nonce: stripe_checkout_vars.checkout_nonce,
                cart: JSON.stringify(cartArray)
            },
            success: function(response) {
                if (response.success) {
                    window.location.href = response.data.url;
                } else {
                    console.error('Error creating checkout session:', response.data);
                    button.prop('disabled', false).text('Checkout');
                    alert('There was an error processing your checkout. Please try again.');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Checkout');
                alert('There was an error processing your checkout. Please try again.');
            }
        });
    });

    $(document).ready(function() {
        fetchProducts();
        initShippingRate();
        updateCartEfficiently();
    });
})(jQuery);