import React from 'react';
import { Menu, Transition } from '@headlessui/react';
import { ChevronDownIcon, EyeIcon, PencilIcon, TrashIcon, BanknotesIcon, ReceiptPercentIcon } from '@heroicons/react/24/outline';

const ActionsMenu = ({ onView, onMakePayment, onAddExpense, onEdit, onDelete }) => {
  return (
    <Menu as="div" className="relative inline-block text-left">
      <div>
        <Menu.Button className="inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-3 py-1.5 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
          Actions
          <ChevronDownIcon className="-mr-1 ml-2 h-5 w-5" aria-hidden="true" />
        </Menu.Button>
      </div>

      <Transition
        enter="transition ease-out duration-100"
        enterFrom="transform opacity-0 scale-95"
        enterTo="transform opacity-100 scale-100"
        leave="transition ease-in duration-75"
        leaveFrom="transform opacity-100 scale-100"
        leaveTo="transform opacity-0 scale-95"
      >
        <Menu.Items className="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-10">
          <div className="py-1">
            <Menu.Item>
              {({ active }) => (
                <button onClick={onView} className={`${active ? 'bg-gray-100' : ''} flex items-center px-4 py-2 text-sm text-gray-700 w-full text-left`}>
                  <EyeIcon className="mr-3 h-5 w-5 text-gray-400" aria-hidden="true" />
                  View History
                </button>
              )}
            </Menu.Item>
            <Menu.Item>
              {({ active }) => (
                <button onClick={onMakePayment} className={`${active ? 'bg-gray-100' : ''} flex items-center px-4 py-2 text-sm text-gray-700 w-full text-left`}>
                  <BanknotesIcon className="mr-3 h-5 w-5 text-green-500" aria-hidden="true" />
                  Make Payment
                </button>
              )}
            </Menu.Item>
            <Menu.Item>
              {({ active }) => (
                <button onClick={onAddExpense} className={`${active ? 'bg-gray-100' : ''} flex items-center px-4 py-2 text-sm text-gray-700 w-full text-left`}>
                  <ReceiptPercentIcon className="mr-3 h-5 w-5 text-orange-500" aria-hidden="true" />
                  Add Expense
                </button>
              )}
            </Menu.Item>
            <Menu.Item>
              {({ active }) => (
                <button onClick={onEdit} className={`${active ? 'bg-gray-100' : ''} flex items-center px-4 py-2 text-sm text-gray-700 w-full text-left`}>
                  <PencilIcon className="mr-3 h-5 w-5 text-gray-400" aria-hidden="true" />
                  Edit
                </button>
              )}
            </Menu.Item>
            <Menu.Item>
              {({ active }) => (
                <button onClick={onDelete} className={`${active ? 'bg-gray-100' : ''} flex items-center px-4 py-2 text-sm text-red-600 w-full text-left`}>
                  <TrashIcon className="mr-3 h-5 w-5 text-red-400" aria-hidden="true" />
                  Delete
                </button>
              )}
            </Menu.Item>
          </div>
        </Menu.Items>
      </Transition>
    </Menu>
  );
};

export default ActionsMenu;