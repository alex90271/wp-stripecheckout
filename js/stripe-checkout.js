(function($) {
    if (stripe_checkout_vars.store_disabled == '1') {
        // Store is disabled, don't initialize anything
        return;
    }
    
    let cart = {};
    let shippingRate = null;

    function fetchProducts() {
        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fetch_stripe_products'
            },
            success: function(response) {
                if (response.success) {
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
                    <h3>${product.name}</h3>
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

    function updateCart() {
        const cartEl = $('#cart');
        cartEl.empty();
        let subtotal = 0;

        Object.values(cart).forEach(item => {
            subtotal += item.price * item.quantity;
            cartEl.append(`
                <div class="cart-item">
                    <span>${item.quantity}x ${item.name}</span>
                    <button class="remove-from-cart" data-product-id="${item.id}">Remove</button>
                </div>
            `);
        });

        cartEl.append(`<div class="cart-subtotal">Subtotal: ${formatPrice(subtotal, 'USD')}</div>`);

        if (shippingRate) {
            cartEl.append(`
                <div class="cart-shipping">
                    Shipping: ${shippingRate.display_name}
                    ${formatPrice(shippingRate.amount, shippingRate.currency)}
                </div>
            `);

            const total = subtotal + shippingRate.amount;
            cartEl.append(`<div class="cart-total">Total: ${formatPrice(total, 'USD')}</div>`);
        } else {
            cartEl.append(`<div class="cart-shipping">Shipping: Not calculated</div>`);
        }

        // Update all "Add to Cart" buttons
        $('.add-to-cart').each(function() {
            const productId = $(this).data('product-id');
            const quantity = cart[productId] ? cart[productId].quantity : 0;
            $(this).text(quantity > 0 ? `Add to Cart (${quantity})` : 'Add to Cart');
        });
    }

    function initShippingRate() {
        if (stripe_checkout_vars.shipping_rate_info) {
            shippingRate = stripe_checkout_vars.shipping_rate_info;
        }
    }

    $(document).on('click', '.add-to-cart', function() {
        const productId = $(this).data('product-id');
        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'get_stripe_product',
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    if (cart[productId]) {
                        cart[productId].quantity += 1;
                    } else {
                        cart[productId] = { ...response.data, quantity: 1 };
                    }
                    updateCart();
                } else {
                    console.error('Error adding product to cart:', response.data);
                }
            }
        });
    });

    $(document).on('click', '.remove-from-cart', function() {
        const productId = $(this).data('product-id');
        if (cart[productId]) {
            if (cart[productId].quantity > 1) {
                cart[productId].quantity -= 1;
            } else {
                delete cart[productId];
            }
            updateCart();
        }
    });

    $('#checkout-button').on('click', function() {
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
                }
            }
        });
    });

    $(document).ready(function() {
        fetchProducts();
        initShippingRate();
        updateCart();
    });
})(jQuery);