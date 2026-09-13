export default function ApplicationLogo(props) {
    return (
        <img
            {...props}
            src="https://raw.githubusercontent.com/simple-icons/simple-icons/develop/icons/coinbase.svg"
            alt="App Logo"
            onError={(e) => { e.currentTarget.onerror = null; e.currentTarget.src = '/logo.png'; }}
        />
    );
}
