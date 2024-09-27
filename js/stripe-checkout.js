(function($) {
    let cart = [];

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
        products.forEach(product => {
            const priceDisplay = product.price 
                ? `Price: ${formatPrice(product.price, product.currency)}` 
                : 'Price not available';
            
            productList.append(`
                <div class="product">
                    <h3>${product.name}</h3>
                    <p>${product.description || 'No description available'}</p>
                    <p>${priceDisplay}</p>
                    ${product.price ? `<button class="add-to-cart" data-product-id="${product.id}">Add to Cart</button>` : ''}
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
        let groupedCart = groupCartItems(cart);

        groupedCart.forEach(item => {
            subtotal += item.price * item.quantity;
            cartEl.append(`
                <div class="cart-item">
                    <span>${item.quantity}x ${item.name}</span>
                    <span>${formatPrice(item.price * item.quantity, item.currency)}</span>
                    <button class="remove-from-cart" data-product-id="${item.id}">Remove</button>
                </div>
            `);
        });

        // Display subtotal
        cartEl.append(`<div class="cart-subtotal">Subtotal: ${formatPrice(subtotal, 'USD')}</div>`);

        // Display shipping information if available
        if (stripe_checkout_vars.shipping_rate_id) {
            cartEl.append(`<div class="cart-shipping">Shipping: To be calculated at checkout</div>`);
        }
    }

    function groupCartItems(cart) {
        let groupedCart = {};
        cart.forEach(item => {
            if (groupedCart[item.id]) {
                groupedCart[item.id].quantity += 1;
            } else {
                groupedCart[item.id] = { ...item, quantity: 1 };
            }
        });
        return Object.values(groupedCart);
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
                    cart.push(response.data);
                    updateCart();
                } else {
                    console.error('Error adding product to cart:', response.data);
                }
            }
        });
    });

    $(document).on('click', '.remove-from-cart', function() {
        const productId = $(this).data('product-id');
        const index = cart.findIndex(item => item.id === productId);
        if (index !== -1) {
            cart.splice(index, 1);
        }
        updateCart();
    });

    $('#checkout-button').on('click', function() {
        $.ajax({
            url: stripe_checkout_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'create_checkout_session',
                cart: JSON.stringify(cart)
            },
            success: function(response) {
                if (response.success) {
                    // Redirect to Stripe Checkout
                    window.location.href = response.data.url;
                } else {
                    console.error('Error creating checkout session:', response.data);
                }
            }
        });
    });

    $(document).ready(function() {
        fetchProducts();
    });
})(jQuery);