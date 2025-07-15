<style>
.header-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 0;
}
.logo {
    font-size: 2rem;
    font-weight: bold;
    color: #2c3e50;
}
nav {
    flex: 1;
    display: flex;
    justify-content: flex-end;
}
nav ul {
    display: flex;
    flex-direction: row;
    list-style: none;
    padding: 0;
    margin: 0;
    gap: 1.5rem;
}
nav ul li {
    display: inline-block;
}
nav ul li a {
    text-decoration: none;
    color: #2c3e50;
    padding: 0.5rem 1.2rem;
    font-weight: 500;
    border-radius: 4px;
    transition: background 0.2s, color 0.2s;
}
nav ul li a.active,
nav ul li a:hover {
    background: #2c3e50;
    color: #fff;
}
@media (max-width: 700px) {
    .header-container {
        flex-direction: column;
        align-items: flex-start;
    }
    nav {
        width: 100%;
        justify-content: flex-start;
    }
    nav ul {
        gap: 0.5rem;
    }
    nav ul li a {
        padding: 0.5rem 0.7rem;
    }
}
</style>
<header>
    <div class="container header-container">
        <div class="logo">Community Library</div>
        <nav>
            <ul>
                <li><a href="/member/index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Home</a></li>
                <li><a href="/member/catalog.php" class="<?= basename($_SERVER['PHP_SELF']) == 'catalog.php' ? 'active' : '' ?>">Catalog</a></li>
                <li><a href="/member/about.php" class="<?= basename($_SERVER['PHP_SELF']) == 'about.php' ? 'active' : '' ?>">About</a></li>
                <li><a href="/member/contact.php" class="<?= basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : '' ?>">Contact</a></li>
                <li><a href="/member/login.php" class="<?= basename($_SERVER['PHP_SELF']) == 'login.php' ? 'active' : '' ?>">Login</a></li>
                <li><a href="/member/register.php" class="<?= basename($_SERVER['PHP_SELF']) == 'register.php' ? 'active' : '' ?>">Register</a></li>
            </ul>
        </nav>
    </div>
</header> 