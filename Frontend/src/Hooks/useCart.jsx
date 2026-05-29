import { useSyncExternalStore } from "react";
import { cartStore } from "../Store/cartStore";

export const getItemKey = (item) => {
  return item.product_id !== null && item.product_id !== undefined
    ? `product_${item.product_id}`
    : `offer_${item.offer_id}`;
};

const useCart = () => {
  const cart = useSyncExternalStore(
    cartStore.subscribe,
    cartStore.getSnapshot
  );

  return {
    cart,
    addItem: cartStore.addItem,
    updateQuantity: cartStore.updateQuantity,
    removeItem: cartStore.removeItem,
    clearCart: cartStore.clearCart,
  };
};

export default useCart;


// import { useState, useEffect, useContext } from "react";
// import { MyContext } from "../Context/Context";

// const CART_KEY = "user_cart";

// const generateTimestamp = () => new Date().toISOString();

// // const getItemKey = (item) => item.product_id ?? item.offer_id;

// export const getItemKey = (item) => {
//   return item.product_id !== null && item.product_id !== undefined
//     ? `product_${item.product_id}`
//     : `offer_${item.offer_id}`;
// };



// const createEmptyCart = (userId = null) => ({
//   id: 1,
//   user_id: userId,
//   created_at: generateTimestamp(),
//   updated_at: generateTimestamp(),
//   cart_item: [],
// });

// const useCart = () => {
//   const [cart, setCart] = useState(createEmptyCart());
//   const { setVersion } = useContext(MyContext);

//   const forceUpdate = () => setVersion((v) => v + 1)

//   useEffect(() => {
//     const stored = sessionStorage.getItem(CART_KEY);
//     if (stored) {
//       setCart(JSON.parse(stored));
//     }
//   }, []);

//   const updateCart = (updatedCart) => {
//     sessionStorage.setItem(CART_KEY, JSON.stringify(updatedCart));
//     setCart(updatedCart);
//     forceUpdate();
//   };

//   const addItem = ({
//     product_id = null,
//     offer_id = null,
//     quantity = 1,
//     piece_price,
//     type_stock = "Market",
//     color_band = null,
//     color_dial = null,
//   }) => {
//     const inputKey = product_id ?? offer_id;
//     const now = generateTimestamp();

//     const stored = sessionStorage.getItem(CART_KEY);
//     const latestCart = stored ? JSON.parse(stored) : cart;

//     const existingItemIndex = latestCart.cart_item.findIndex(
//       (item) => getItemKey(item) === inputKey
//     );

//     const updatedItems = [...latestCart.cart_item];

//     if (existingItemIndex !== -1) {
//       const existingItem = updatedItems[existingItemIndex];
//       const newQty = existingItem.quantity + quantity;
//       updatedItems[existingItemIndex] = {
//         ...existingItem,
//         quantity: newQty,
//         total_price: (newQty * parseFloat(existingItem.piece_price)).toFixed(2),
//         updated_at: now,
//       };
//     } else {
//       const newItem = {
//         id: Date.now(),
//         cart_id: latestCart.id,
//         product_id,
//         offer_id,
//         quantity,
//         piece_price: parseFloat(piece_price).toFixed(2),
//         total_price: (quantity * parseFloat(piece_price)).toFixed(2),
//         type_stock,
//         color_band,
//         color_dial,
//         created_at: now,
//         updated_at: now,
//       };
//       updatedItems.push(newItem);
//     }

//     const updatedCart = {
//       ...latestCart,
//       updated_at: now,
//       cart_item: updatedItems,
//     };

//     updateCart(updatedCart);
//   };

//   const updateQuantity = (identifier, newQuantity) => {
//     const now = generateTimestamp();

//     const stored = sessionStorage.getItem(CART_KEY);
//     const latestCart = stored ? JSON.parse(stored) : cart;

//     const updatedItems = latestCart.cart_item.map((item) =>
//       getItemKey(item) === identifier
//         ? {
//             ...item,
//             quantity: newQuantity,
//             total_price: (parseFloat(item.piece_price) * newQuantity).toFixed(2),
//             updated_at: now,
//           }
//         : item
//     );

//     const updatedCart = {
//       ...latestCart,
//       updated_at: now,
//       cart_item: updatedItems,
//     };

//     updateCart(updatedCart);
//   };

//   const removeItem = (identifier) => {
//     const now = generateTimestamp();

//     const stored = sessionStorage.getItem(CART_KEY);
//     const latestCart = stored ? JSON.parse(stored) : cart;

//     const updatedItems = latestCart.cart_item.filter(
//       (item) => getItemKey(item) !== identifier
//     );

//     const updatedCart = {
//       ...latestCart,
//       updated_at: now,
//       cart_item: updatedItems,
//     };

//     updateCart(updatedCart);
//   };

//   const clearCart = () => {
//     const cleared = createEmptyCart(cart.user_id);
//     sessionStorage.removeItem(CART_KEY);
//     setCart(cleared);
//   };

//   return {
//     cart,
//     addItem,
//     updateQuantity,
//     removeItem,
//     clearCart,
//   };
// };

// export default useCart;
