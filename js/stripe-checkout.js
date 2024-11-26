(function ($) {
    if (stripe_checkout_vars.store_disabled == '1') {
        // Store is disabled, don't initialize anything
        return;
    }

    let cart = {};
    let shippingRate = null;
    let productCache = {};  // Cache for product data

    function fetchProducts() {
        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fetch_stripe_products'
            },
            success: function (response) {
                if (response.success) {
                    // Cache product data when first fetched
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

        Object.values(cart).forEach(item => {
            subtotal += item.price * item.quantity;
            cartHTML += `
                <div class="cart-item">
                    <span class="cart-item-quantity"><strong style="padding-right: 5px">${item.quantity}x</strong> ${item.name}</span>
                    <button class="remove-from-cart" data-product-id="${item.id}">X</button>
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

        // Single DOM update
        cartEl.html(cartHTML);

        // Update cart button visibility
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

    // Debounce function to prevent rapid clicks
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

    // Debounced click handler for add to cart
    const debouncedAddToCart = debounce(function(productId, button) {
        if (productCache[productId]) {
            if (cart[productId]) {
                cart[productId].quantity += 1;
            } else {
                cart[productId] = { ...productCache[productId], quantity: 1 };
            }
            
            // Update only the specific button clicked
            button.text(`Add to Cart (${cart[productId].quantity})`);
            
            // Update cart display efficiently
            updateCartEfficiently();
        }
    }, 250); // 250ms debounce time

    $(document).on('click', '.add-to-cart', function(e) {
        e.preventDefault();
        const productId = $(this).data('product-id');
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
        
        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'create_checkout_session',
                cart: JSON.stringify(Object.values(cart))
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