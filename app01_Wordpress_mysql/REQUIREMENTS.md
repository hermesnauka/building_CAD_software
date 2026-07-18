# System Requirements

## 1. Functional Requirements
*   **Content Management:** Authors must be able to publish fast, SEO-optimized articles and news about the latest construction software discoveries.
*   **E-Commerce:** The platform must support the digital delivery and sale of CAD extensions and construction software licenses.
*   **Payments:** Seamless and secure integration with Polish and international payment methods: BLIK, PayPal, and Direct Bank Login (e.g., PayU, Przelewy24, Tpay).
*   **Educational Platform:** Ability to host and restrict access to educational modules (videos, tutorials, webinars) for verified or paying users.
*   **Database:** Utilize MySQL for efficient data storage and retrieval.

## 2. Non-Functional Requirements
*   **Performance:** Fast loading times (Time to First Byte < 500ms) to support quick content browsing.
*   **Scalability:** Capable of handling traffic spikes during new software releases or marketing campaigns.
*   **Usability:** Fully mobile-responsive design for reading news and purchasing extensions on the go.

## 3. Security Requirements (SSDLC Focus)
*   **Authentication:** Enforce strong password policies and Multi-Factor Authentication (MFA) for all administrative and editor accounts.
*   **Authorization:** Implement strict Role-Based Access Control (RBAC). Subscribers/Students cannot access WP admin panels.
*   **Data Protection:** 
    *   TLS 1.2+ mandatory for all connections.
    *   No Credit Card or banking credentials stored on the MySQL database (use payment provider tokenization).
*   **Input Validation:** Strict sanitization of all user inputs (comments, contact forms, checkout fields) to prevent Cross-Site Scripting (XSS) and SQL Injection (SQLi).
*   **Session Management:** Secure session cookies (HttpOnly, Secure, SameSite) and automatic session timeouts for idle users.
