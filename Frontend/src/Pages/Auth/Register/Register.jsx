import { useState } from "react";
import DOMPurify from "dompurify";
import "../auth.css";

function Register() {
    const [firstName, setFirstName] = useState("");
    const [lastName, setLastName] = useState("");
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [confirmPassword, setConfirmPassword] = useState("");
    const [phoneNumber, setPhoneNumber] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [loading, setLoading] = useState(false);

    const handleRegister = async (e) => {
        e.preventDefault();

        const cleanFirstName = DOMPurify.sanitize(firstName);
        const cleanLastName = DOMPurify.sanitize(lastName);
        const cleanEmail = DOMPurify.sanitize(email);
        const cleanPassword = DOMPurify.sanitize(password);
        const cleanConfirmPassword = DOMPurify.sanitize(confirmPassword);
        const cleanPhoneNumber = DOMPurify.sanitize(phoneNumber);

        if (!cleanFirstName || !cleanLastName || !cleanEmail || !cleanPassword || !cleanConfirmPassword || !cleanPhoneNumber) {
            setError("All fields are required.");
            return;
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(cleanEmail)) {
            setError("Invalid email format.");
            return;
        }

        if (cleanPassword !== cleanConfirmPassword) {
            setError("Passwords do not match.");
            return;
        }

        const phoneRegex = /^[0-9]{10,15}$/;
        if (!phoneRegex.test(cleanPhoneNumber)) {
            setError("Phone number must be 10-15 digits.");
            return;
        }

        setLoading(true);
        try {
            const formData = new FormData();
            formData.append("first_name", cleanFirstName);
            formData.append("last_name", cleanLastName);
            formData.append("email", cleanEmail);
            formData.append("password", cleanPassword);
            formData.append("password_confirmation", cleanConfirmPassword);
            formData.append("phone_number", cleanPhoneNumber);

            const response = await fetch("https://dash.watchizereg.com/api/register", {
                method: "POST",
                headers: {
                    "Api-Code": "NbmFylY0vcwnhxUrm1udMgcX1MtPYb4QWXy1EKqVenm6uskufcXKeHh5W4TM5Iv0",
                },
                body: formData,
            });

            const data = await response.json();
            // console.log("Server Response:", data);

            if (response.ok) {
                setSuccess("Registration successful!");
                sessionStorage.setItem("user_id", data.id);
                sessionStorage.setItem("first_name", data.first_name);
                sessionStorage.setItem("last_name", data.last_name);
                sessionStorage.setItem("email", data.email);
                sessionStorage.setItem("phone_number", data.phone_number);
                sessionStorage.setItem(
                    "image",
                    data.image ? `https://dash.watchizereg.com/Uploads_Images/User/${data.image}` : null
                );
                sessionStorage.setItem("token", data.token);
                setTimeout(() => {
                    window.location.href = "/";
                }, 2000);
            } else {
                setError(data.error || "Registration failed. Please try again.");
            }
        } catch {
            // console.error("Error:", err);
            setError("An error occurred. Please check your connection and try again.");
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="register" style={{ height: "100%", width: "100vw", zIndex: 10000, position: "fixed", top: "0", left: "0" }}>
            <div className="container" style={{ height: "100%" }}>
                <div className="row justify-content-center px-md-5 px-2" style={{ height: "100%" }}>
                    <div className="col-md-6 col-12 d-flex flex-column justify-content-center align-items-center" style={{ height: "100%" }}>
                        <div className="register-form col-12 p-md-5 p-3 bg-light rounded-3">
                            <h2 className="text-center">Register</h2>
                            {error && <div className="alert alert-danger">{error}</div>}
                            {success && <div className="alert alert-success">{success}</div>}
                            {loading && <div className="alert alert-info">Processing...</div>}
                            <form onSubmit={handleRegister}>
                                <div className="mb-3 d-flex justify-content-between">
                                    <div className="col-6 pe-1">
                                        <label htmlFor="firstName" className="form-label">First Name</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            id="firstName"
                                            value={firstName}
                                            onChange={(e) => setFirstName(e.target.value)}
                                        />
                                    </div>
                                    <div className="col-6 ps-1">
                                        <label htmlFor="lastName" className="form-label">Last Name</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            id="lastName"
                                            value={lastName}
                                            onChange={(e) => setLastName(e.target.value)}
                                        />
                                    </div>
                                </div>
                                <div className="mb-3">
                                    <label htmlFor="email" className="form-label">Email Address</label>
                                    <input
                                        type="email"
                                        className="form-control"
                                        id="email"
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                    />
                                </div>
                                <div className="mb-3">
                                    <label htmlFor="password" className="form-label">Password</label>
                                    <div className="position-relative">
                                        <input
                                            type={showPassword ? "text" : "password"}
                                            className="form-control"
                                            id="password"
                                            value={password}
                                            onChange={(e) => setPassword(e.target.value)}
                                        />
                                        <button
                                            type="button"
                                            className="btn position-absolute top-50 end-0 translate-middle-y"
                                            style={{ padding: "0 10px" }}
                                            onClick={() => setShowPassword(!showPassword)}
                                        >
                                            {showPassword ? "👁️" : "🙈"}
                                        </button>
                                    </div>
                                </div>
                                <div className="mb-3">
                                    <label htmlFor="confirmPassword" className="form-label">Confirm Password</label>
                                    <input
                                        type={showPassword ? "text" : "password"}
                                        className="form-control"
                                        id="confirmPassword"
                                        value={confirmPassword}
                                        onChange={(e) => setConfirmPassword(e.target.value)}
                                    />
                                </div>
                                <div className="mb-3">
                                    <label htmlFor="phoneNumber" className="form-label">Phone Number</label>
                                    <input
                                        type="text"
                                        className="form-control"
                                        id="phoneNumber"
                                        value={phoneNumber}
                                        onChange={(e) => setPhoneNumber(e.target.value)}
                                    />
                                </div>
                                <button type="submit" className="btn btn-primary" disabled={loading}>
                                    {loading ? "Submitting..." : "Register"}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default Register;
