import { router,usePage } from '@inertiajs/react';


const Navbar = ({ auth }) => {
  
  // Check if the admin is viewing as another user
  const isViewingAs = usePage().props.auth.isViewingAs; // Assuming `isViewingAs` is passed from the backend

  // Handle switching back to the admin account
  const handleSwitchBack = () => {
    router.post('/admin/switch-back', {}, {
      onSuccess: () => {
        // Redirect to the home page
        router.visit('/dashboard');
      },
      onError: (errors) => {
        console.error('Error switching back:', errors);
      },
    });
  };

  return (
    <div className='flex items-center justify-between p-4'>
      {/* SEARCH BAR */}
      <div className='hidden md:flex items-center gap-2 text-xs rounded-full ring-[1.5px] ring-gray-300 px-2'>
        <img src="/search.png" alt="" width={14} height={14} />
        <input
          type="text"
          placeholder="Search..."
          className="w-[200px] p-2 bg-transparent outline-none border-none focus:ring-0"
        />
      </div>

      {/* ICONS AND USER */}
      <div className='flex items-center gap-6 justify-end w-full'>
       

        {/* Announcement Icon */}
        <div className='bg-white rounded-full w-7 h-7 flex items-center justify-center cursor-pointer relative'>
          <img src="/announcement.png" alt="" width={20} height={20} />
          <div className='absolute -top-3 -right-3 w-5 h-5 flex items-center justify-center bg-purple-500 text-white rounded-full text-xs'>1</div>
        </div>

        {/* User Info */}
        <div className='flex flex-col'>
          <span className="text-xs leading-3 font-medium">{auth.name}</span>
          <span className="text-[10px] text-gray-500 text-right">{auth.role}</span>
        </div>

        {/* User Avatar */}
        <img src="/avatar.png" alt="" width={36} height={36} className="rounded-full" />

        {/* Switch Back Button (Visible only when admin is viewing as another user) */}
        { isViewingAs && (
          <button
            onClick={handleSwitchBack}
            className="p-2 bg-blue-500 text-white rounded-full hover:bg-blue-600 transition duration-200"
          >
            Switch Back to Admin
          </button>
        )}
      </div>
    </div>
  );
};

export default Navbar;