# MarketKing-Bridge-FluxStore-FluxBuilder
Custom WordPress plugin to integrate MarketKing vendors with FluxStore FluxBuilder via REST API.

# MarketKing Bridge (FluxStore)

A custom WordPress plugin that exposes REST API endpoints to integrate the **MarketKing multivendor plugin** with **FluxStore / FluxBuilder**.

## 📡 REST API Endpoints
| Endpoint | Description |
|-----------|--------------|
| `/mk/v1/ping` | Health check |
| `/mk/v1/vendors` | List vendors |
| `/mk/v1/vendors/{id}` | Vendor details |
| `/mk/v1/vendors/{id}/products` | Vendor products |
| `/mk/v1/vendors/{id}/reviews` | Vendor reviews |
| `/mk/v1/vendors/{id}/coupons` | Vendor coupons |
| `/mk/v1/vendors/by-product/{product_id}` | Get vendor by product |
| `/mk/v1/vendors/search?q=term` | Search vendors |
| `/mk/v1/vendors/sections` | Featured, new, and top vendors |

## 🔧 Features
- Fully compatible with MarketKing vendor structure
- Outputs JSON similar to WCFM/Dokan for FluxStore compatibility
- Includes product counts, social links, banners, and rating data

## 🧱 Author
**Fatih Yörük**  
[artQart.com](https://artqart.com)
